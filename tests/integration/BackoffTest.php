<?php

/**
 * Integration tests for the Remote Falcon backoff gate and the interrupt
 * poll floor (season resilience P1-9), against mock FPP and RF servers.
 *
 * What they guard: when RF fails (no response, 429, 5xx) the listener stops
 * hammering it, honors Retry-After, keeps the heartbeat on its own cadence,
 * and never lets any of it touch FPP status probing.
 */
final class BackoffTest extends IntegrationTestCase {

    private string $logFilePath;

    protected function setUp(): void {
        parent::setUp();
        $this->logFilePath = sys_get_temp_dir() . '/rf-backoff-test-' . uniqid() . '.log';
        $GLOBALS['logFile'] = $this->logFilePath;
        $GLOBALS['verboseLogging'] = false;
        $GLOBALS['fppBaseUrl'] = $this->fppMock->getBaseUrl();
        $GLOBALS['pluginsApiPath'] = $this->rfMock->getBaseUrl();
        $GLOBALS['lastQueuedSequence'] = '';
        $GLOBALS['lastQueuedTime'] = 0;
        $GLOBALS['remotePlaylist'] = 'MyRfPlaylist';
        $GLOBALS['rfStatus'] = [];
    }

    protected function tearDown(): void {
        @unlink($this->logFilePath);
        foreach (['logFile', 'verboseLogging', 'fppBaseUrl', 'pluginsApiPath', 'lastQueuedSequence',
                  'lastQueuedTime', 'remotePlaylist', 'rfStatus', 'lastControlFetchAt',
                  'emptyFetchSinceModeRead', 'lastInterruptPollAt'] as $key) {
            unset($GLOBALS[$key]);
        }
        rf_backoff_reset();
        parent::tearDown();
    }

    private function log(): string {
        return is_file($this->logFilePath) ? file_get_contents($this->logFilePath) : '';
    }

    private function rfPaths(): array {
        return array_map(fn ($r) => $r['path'], $this->rfMock->getRecordings());
    }

    private function fppStatus(int $secondsRemaining, string $playlist = 'OtherPlaylist'): stdClass {
        $s = new stdClass();
        $s->status_name = 'playing';
        $s->current_sequence = 'current.fseq';
        $s->seconds_remaining = $secondsRemaining;
        $s->current_playlist = new stdClass();
        $s->current_playlist->playlist = $playlist;
        return $s;
    }

    // -------- Transport gate --------

    public function testFailurePausesFurtherRfCallsWithoutSendingThem(): void {
        $this->rfMock->setRoute('/highestVotedPlaylist', ['status' => 503, 'body' => 'down', 'headers' => ['Retry-After' => '15']]);

        $this->assertNull(rf_http_rf_get_highest_voted($this->rfMock->getBaseUrl(), 'tok'));
        $this->assertNull(rf_http_rf_get_highest_voted($this->rfMock->getBaseUrl(), 'tok'));
        $this->assertFalse(rf_http_rf_update_whats_playing($this->rfMock->getBaseUrl(), 'tok', 'song'));

        // Only the first call reached the server.
        $this->assertSame(['/highestVotedPlaylist'], $this->rfPaths());
        $this->assertTrue(rf_backoff_active());
        $this->assertSame(1, rf_backoff_state()['failures']);
    }

    public function testRetryAfterOn503IsHonored(): void {
        $this->rfMock->setRoute('/nextPlaylistInQueue', ['status' => 503, 'body' => 'down', 'headers' => ['Retry-After' => '15']]);

        rf_http_rf_get_next_in_queue($this->rfMock->getBaseUrl(), 'tok');

        $state = rf_backoff_state();
        $this->assertSame(15.0, $state['delay']);
        $this->assertTrue(rf_backoff_active(rf_monotonic_seconds() + 14.0));
        $this->assertFalse(rf_backoff_active(rf_monotonic_seconds() + 15.5));
        $this->assertStringContainsString('failed (HTTP 503). Pausing Remote Falcon calls for 15.0s (server asked for 15s)', $this->log());
    }

    public function testRetryAfterHttpDateOn429IsHonored(): void {
        $date = gmdate('D, d M Y H:i:s', time() + 20) . ' GMT';
        $this->rfMock->setRoute('/remotePreferences', ['status' => 429, 'body' => 'slow down', 'headers' => ['Retry-After' => $date]]);

        rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'tok');

        $delay = rf_backoff_state()['delay'];
        $this->assertGreaterThanOrEqual(18.0, $delay);
        $this->assertLessThanOrEqual(20.0, $delay);
    }

    public function testNoResponseCountsAsAFailure(): void {
        // Port 1 on localhost refuses the connection immediately.
        $this->assertNull(rf_http_rf_get_next_in_queue('http://127.0.0.1:1', 'tok', 2));

        $this->assertSame(1, rf_backoff_state()['failures']);
        $this->assertStringContainsString('failed (no response)', $this->log());
    }

    public function testTransportFailureDropsThePooledConnection(): void {
        $this->rfMock->setRoute('/remotePreferences', ['status' => 200, 'body' => '{}']);
        rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'tok');
        $warm = _rf_http_rf_curl_handle();
        rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'tok');
        $this->assertSame($warm, _rf_http_rf_curl_handle(), 'a success keeps the warm connection');

        rf_http_rf_heartbeat('http://127.0.0.1:1', 'tok', 2);

        $this->assertNotSame($warm, _rf_http_rf_curl_handle(), 'a transport failure must not reuse a possibly dead connection');
    }

    public function testHttpErrorKeepsThePooledConnection(): void {
        $this->rfMock->setRoute('/remotePreferences', ['status' => 503, 'body' => 'down']);
        $warm = _rf_http_rf_curl_handle();
        rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'tok');

        $this->assertSame($warm, _rf_http_rf_curl_handle(), 'the server answered, so the connection is healthy');
    }

    public function testPausedWhatsPlayingUpdatesDoNotLogErrors(): void {
        $this->rfMock->setRoute('/highestVotedPlaylist', ['status' => 503, 'body' => 'down', 'headers' => ['Retry-After' => '15']]);
        rf_http_rf_get_highest_voted($this->rfMock->getBaseUrl(), 'tok');
        $this->assertTrue(rf_backoff_active());

        $this->assertFalse(updateWhatsPlaying('song', 'tok'));
        $this->assertFalse(updateNextScheduledSequenceInRf('next', 'tok'));

        $this->assertStringNotContainsString('ERROR - Failed to update', $this->log());
        $this->assertSame(['/highestVotedPlaylist'], $this->rfPaths());
    }

    public function testARealWhatsPlayingFailureStillLogsAnError(): void {
        $this->rfMock->setRoute('/updateWhatsPlaying', ['status' => 500, 'body' => 'boom']);

        $this->assertFalse(updateWhatsPlaying('song', 'tok'));

        $this->assertStringContainsString("ERROR - Failed to update what's playing", $this->log());
    }

    public function testNotFoundDoesNotBackOff(): void {
        $this->rfMock->setRoute('/remotePreferences', ['status' => 404, 'body' => 'unknown token']);

        rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'tok');
        rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'tok');

        $this->assertCount(2, $this->rfPaths());
        $this->assertFalse(rf_backoff_active());
    }

    public function testSuccessAfterThePauseResetsAndLogsRecovery(): void {
        $this->rfMock->setRoute('/remotePreferences', ['status' => 500, 'body' => 'oops']);
        rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'tok');
        $this->elapseRfBackoff();
        rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'tok');
        $this->assertSame(2, rf_backoff_state()['failures']);

        $this->elapseRfBackoff();
        $this->rfMock->setRoute('/remotePreferences', ['body' => ['viewerControlMode' => 'voting']]);
        $this->assertNotNull(rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'tok'));

        $this->assertSame(0, rf_backoff_state()['failures']);
        $this->assertFalse(rf_backoff_active());
        $this->assertStringContainsString('Remote Falcon is responding again (after 2 failed calls)', $this->log());
        // First failure of a streak logs at normal level, later ones only verbose.
        $this->assertSame(1, substr_count($this->log(), 'Pausing Remote Falcon calls'));
    }

    // -------- Heartbeat --------

    public function testHeartbeatIsSentWhileRfCallsArePaused(): void {
        $this->rfMock->setRoute('/nextPlaylistInQueue', ['status' => 503, 'body' => 'down', 'headers' => ['Retry-After' => '15']]);
        $this->rfMock->setRoute('/fppHeartbeat', ['status' => 204, 'body' => '']);
        rf_http_rf_get_next_in_queue($this->rfMock->getBaseUrl(), 'tok');
        $before = rf_backoff_state();

        $this->assertTrue(rf_http_rf_heartbeat($this->rfMock->getBaseUrl(), 'tok'));

        $this->assertContains('/fppHeartbeat', $this->rfPaths());
        // A heartbeat success doesn't cut a server-requested pause short.
        $this->assertSame($before, rf_backoff_state());
        $this->assertTrue(rf_backoff_active());
    }

    public function testFailedHeartbeatDoesNotStartABackoff(): void {
        $this->rfMock->setRoute('/fppHeartbeat', ['status' => 503, 'body' => 'down']);

        $this->assertFalse(rf_http_rf_heartbeat($this->rfMock->getBaseUrl(), 'tok'));

        $this->assertFalse(rf_backoff_active());
        $this->assertSame(0, rf_backoff_state()['failures']);
    }

    // -------- FPP is never gated --------

    public function testFppStatusProbingIgnoresTheRfBackoff(): void {
        $this->rfMock->setRoute('/remotePreferences', ['status' => 503, 'body' => 'down', 'headers' => ['Retry-After' => '60']]);
        rf_http_rf_get_preferences($this->rfMock->getBaseUrl(), 'tok');
        $this->assertTrue(rf_backoff_active());
        $this->fppMock->setRoute('/api/system/status', ['body' => ['status_name' => 'playing']]);

        $status = getFppStatus();

        $this->assertSame('playing', $status->status_name);
    }

    // -------- Interrupt mode --------

    public function testInterruptModePollsAtMostEveryTwoSeconds(): void {
        $this->rfMock->setRoute('/nextPlaylistInQueue', ['body' => ['nextPlaylist' => null, 'playlistIndex' => -1]]);

        // Three listener ticks in quick succession: only the first polls.
        for ($i = 0; $i < 3; $i++) {
            doInterruptStuff($this->fppStatus(30), 3, 'jukebox', 0, 'MyRfPlaylist', 'tok');
        }
        $this->assertSame(['/nextPlaylistInQueue'], $this->rfPaths());

        // Two seconds later the next tick polls again.
        $GLOBALS['lastInterruptPollAt'] -= RF_INTERRUPT_POLL_FLOOR_SECONDS;
        doInterruptStuff($this->fppStatus(30), 3, 'jukebox', 0, 'MyRfPlaylist', 'tok');
        $this->assertCount(2, $this->rfPaths());
    }

    public function testInterruptModeDoesNotPollWhileRfCallsArePaused(): void {
        $this->rfMock->setRoute('/highestVotedPlaylist', ['status' => 503, 'body' => 'down', 'headers' => ['Retry-After' => '15']]);
        doInterruptStuff($this->fppStatus(30), 3, 'voting', 0, 'MyRfPlaylist', 'tok');
        $this->assertCount(1, $this->rfPaths());

        // Past the poll floor but still inside Retry-After.
        $GLOBALS['lastInterruptPollAt'] -= RF_INTERRUPT_POLL_FLOOR_SECONDS;
        doInterruptStuff($this->fppStatus(30), 3, 'voting', 0, 'MyRfPlaylist', 'tok');

        $this->assertCount(1, $this->rfPaths());
        $this->assertCount(0, $this->fppMock->getRecordings());
    }

    public function testInterruptModeStillPlaysARequestPromptlyAfterRecovery(): void {
        $this->rfMock->setRoute('/nextPlaylistInQueue', ['status' => 502, 'body' => 'bad gateway']);
        doInterruptStuff($this->fppStatus(30), 3, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        $this->elapseRfBackoff();
        $GLOBALS['lastInterruptPollAt'] -= RF_INTERRUPT_POLL_FLOOR_SECONDS;
        $this->rfMock->setRoute('/nextPlaylistInQueue', ['body' => ['nextPlaylist' => 'urgent.fseq', 'playlistIndex' => 9]]);
        $this->fppMock->setRoute('/api/command/Insert Playlist Immediate*', ['body' => 'ok']);
        doInterruptStuff($this->fppStatus(30), 0, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        $fppPaths = array_map(fn ($r) => $r['path'], $this->fppMock->getRecordings());
        $this->assertSame(['/api/command/Insert Playlist Immediate/MyRfPlaylist/9/9'], $fppPaths);
    }

    // -------- Non-interrupt mode --------

    public function testNonInterruptSkipsTheFetchWhilePausedAndRetriesInTheSameWindow(): void {
        $this->rfMock->setRoute('/updateWhatsPlaying', ['status' => 503, 'body' => 'down']);
        rf_http_rf_update_whats_playing($this->rfMock->getBaseUrl(), 'tok', 'current');
        $this->rfMock->clearRecordings();
        $this->rfMock->setRoute('/nextPlaylistInQueue', ['body' => ['nextPlaylist' => 'happy.fseq', 'playlistIndex' => 4]]);
        $this->fppMock->setRoute('/api/command/Insert Playlist After Current*', ['body' => 'ok']);

        // Inside the fetch window, but RF calls are paused.
        doNonInterruptStuff($this->fppStatus(0, 'MyRfPlaylist'), 1, 'jukebox', 0, 'MyRfPlaylist', 'tok');
        $this->assertCount(0, $this->rfPaths());
        // Not marked as checked, so the next tick in the window can still fetch.
        $this->assertSame('', $GLOBALS['lastQueuedSequence']);

        $this->elapseRfBackoff();
        doNonInterruptStuff($this->fppStatus(0, 'MyRfPlaylist'), 1, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        $this->assertSame(['/nextPlaylistInQueue'], $this->rfPaths());
        $fppPaths = array_map(fn ($r) => $r['path'], $this->fppMock->getRecordings());
        $this->assertSame(['/api/command/Insert Playlist After Current/MyRfPlaylist/4/4'], $fppPaths);
    }
}
