<?php

/**
 * Integration tests for the viewer control mode refresh, the listener status
 * file, the heartbeat body and the remote playlist check (2026.10.08.01).
 *
 * The bug these guard against: the listener read the mode once at startup
 * and fell back to jukebox for the whole session when that read failed, so a
 * voting show's plugin asked /nextPlaylistInQueue forever and votes were
 * never consumed, while the heartbeat kept the dashboard "Connected".
 */
final class ModeRefreshTest extends IntegrationTestCase {

    private string $logFilePath;
    private string $statusPath;

    protected function setUp(): void {
        parent::setUp();
        $this->logFilePath = sys_get_temp_dir() . '/rf-test-' . uniqid() . '.log';
        $this->statusPath = sys_get_temp_dir() . '/rf-test-status-' . uniqid() . '.json';
        $GLOBALS['logFile'] = $this->logFilePath;
        $GLOBALS['verboseLogging'] = false;
        $GLOBALS['rfStatusFile'] = $this->statusPath;
        $GLOBALS['rfStatus'] = [];
        $GLOBALS['fppBaseUrl'] = $this->fppMock->getBaseUrl();
        $GLOBALS['pluginsApiPath'] = $this->rfMock->getBaseUrl();
        $GLOBALS['PLUGIN_VERSION'] = '2026.10.08.01';
        $GLOBALS['lastQueuedSequence'] = '';
        $GLOBALS['lastQueuedTime'] = 0;
        $GLOBALS['remotePlaylist'] = 'MyRfPlaylist';
        unset($GLOBALS['lastControlFetchAt'], $GLOBALS['remotePlaylistWarningLogged'], $GLOBALS['rfStatusWriteFailed']);
        rf_playlist_cache_clear();
        rf_reset_mode_state();
    }

    protected function tearDown(): void {
        @unlink($this->logFilePath);
        @unlink($this->statusPath);
        foreach (['logFile', 'verboseLogging', 'rfStatus', 'fppBaseUrl', 'pluginsApiPath', 'PLUGIN_VERSION',
                  'lastQueuedSequence', 'lastQueuedTime', 'remotePlaylist', 'viewerControlMode',
                  'modeConfirmed', 'modeLastAttemptAt', 'modeReadFailures', 'emptyFetchSinceModeRead',
                  'lastControlFetchAt', 'remotePlaylistWarningLogged', 'rfStatusWriteFailed'] as $key) {
            unset($GLOBALS[$key]);
        }
        // Back to the bootstrap's shared temp path for other test classes.
        $GLOBALS['rfStatusFile'] = sys_get_temp_dir() . '/rf-test-status-' . getmypid() . '.json';
        parent::tearDown();
    }

    private function log(): string {
        return is_file($this->logFilePath) ? file_get_contents($this->logFilePath) : '';
    }

    private function status(): array {
        return json_decode(file_get_contents($this->statusPath), true);
    }

    private function prefs(string $mode): void {
        $this->rfMock->setRoute('/remotePreferences', ['body' => ['viewerControlMode' => $mode]]);
    }

    private function prefsDown(): void {
        $this->rfMock->setRoute('/remotePreferences', ['status' => 503, 'body' => 'unavailable']);
    }

    private function fppStatus(int $secondsRemaining = 0): stdClass {
        $s = new stdClass();
        $s->status_name = 'playing';
        $s->current_sequence = 'current.fseq';
        $s->seconds_remaining = $secondsRemaining;
        $s->current_playlist = new stdClass();
        $s->current_playlist->playlist = 'OtherPlaylist';
        return $s;
    }

    private function rfPaths(): array {
        return array_map(fn ($r) => $r['path'], $this->rfMock->getRecordings());
    }

    // -------- refreshViewerControlMode --------

    public function testRefresh_readsAndConfirmsMode(): void {
        $this->prefs('voting');

        $this->assertTrue(refreshViewerControlMode('tok'));

        $this->assertSame('voting', $GLOBALS['viewerControlMode']);
        $this->assertTrue($GLOBALS['modeConfirmed']);
        $this->assertStringContainsString('Viewer Control Mode: voting', $this->log());
        $status = $this->status();
        $this->assertSame('voting', $status['viewerControlMode']);
        $this->assertTrue($status['modeConfirmed']);
        $this->assertNull($status['modeError']);
    }

    public function testRefresh_failureKeepsJukeboxUnconfirmedAndWarnsOncePerStreak(): void {
        $this->prefsDown();

        $this->assertFalse(refreshViewerControlMode('tok'));
        $this->assertFalse(refreshViewerControlMode('tok'));

        $this->assertSame('jukebox', $GLOBALS['viewerControlMode']);
        $this->assertFalse($GLOBALS['modeConfirmed']);
        $this->assertSame(1, substr_count($this->log(), 'Unable to fetch remote preferences'));
        $this->assertStringContainsString('retrying every 30 seconds', $this->log());
        $this->assertSame(2, $this->status()['modeError']['failures']);
    }

    public function testRefresh_recoversAfterFailures(): void {
        $this->prefsDown();
        refreshViewerControlMode('tok');
        refreshViewerControlMode('tok');
        $this->prefs('voting');

        $this->assertTrue(refreshViewerControlMode('tok'));

        $this->assertSame('voting', $GLOBALS['viewerControlMode']);
        $this->assertStringContainsString('Viewer Control Mode: voting (read after 2 failed attempts)', $this->log());
        $this->assertSame(0, $GLOBALS['modeReadFailures']);
    }

    public function testRefresh_singleFailureIsSingular(): void {
        $this->prefsDown();
        refreshViewerControlMode('tok');
        $this->prefs('jukebox');

        refreshViewerControlMode('tok');

        $this->assertStringContainsString('Viewer Control Mode: jukebox (read after 1 failed attempt)', $this->log());
    }

    public function testRefresh_logsAModeChange(): void {
        $this->prefs('jukebox');
        refreshViewerControlMode('tok');
        $this->prefs('voting');

        refreshViewerControlMode('tok');

        $this->assertSame('voting', $GLOBALS['viewerControlMode']);
        $this->assertStringContainsString('Viewer control mode changed: jukebox -> voting', $this->log());
    }

    public function testRefresh_failedRecheckKeepsTheConfirmedMode(): void {
        $this->prefs('voting');
        refreshViewerControlMode('tok');
        $this->prefsDown();

        $this->assertFalse(refreshViewerControlMode('tok'));

        $this->assertSame('voting', $GLOBALS['viewerControlMode']);
        $this->assertTrue($GLOBALS['modeConfirmed']);
        $this->assertStringContainsString("Unable to re-check viewer control mode with Remote Falcon. Keeping 'voting'", $this->log());
    }

    public function testRestartKeepsAConfirmedModeAsTheFallback(): void {
        $this->prefs('voting');
        refreshViewerControlMode('tok');
        // Restart (settings save) while Remote Falcon blips.
        rf_reset_mode_state($GLOBALS['modeConfirmed'] ? $GLOBALS['viewerControlMode'] : 'jukebox');
        $this->prefsDown();

        refreshViewerControlMode('tok');

        $this->assertSame('voting', $GLOBALS['viewerControlMode']);
        $this->assertFalse($GLOBALS['modeConfirmed']);
        $this->assertStringContainsString("Using 'voting' mode until Remote Falcon can be reached", $this->log());
    }

    public function testRefresh_unrecognisedModeCountsAsAFailure(): void {
        $this->prefs('party');

        $this->assertFalse(refreshViewerControlMode('tok'));
        $this->assertSame('jukebox', $GLOBALS['viewerControlMode']);
        $this->assertFalse($GLOBALS['modeConfirmed']);
    }

    // -------- The Grinnall scenario, end to end --------

    public function testBootTimeFailureNoLongerStrandsAVotingShowInJukebox(): void {
        // Boot: RF unreachable, so the listener starts in jukebox.
        $this->prefsDown();
        refreshViewerControlMode('tok');
        $this->rfMock->setRoute('/nextPlaylistInQueue', ['body' => ['nextPlaylist' => null, 'playlistIndex' => -1]]);
        $this->rfMock->setRoute('/highestVotedPlaylist', ['body' => ['winningPlaylist' => 'Your Idol', 'playlistIndex' => 18]]);
        $this->fppMock->setRoute('/api/command/Insert Playlist After Current*', ['body' => 'ok']);

        doNonInterruptStuff($this->fppStatus(), 1, $GLOBALS['viewerControlMode'], 0, 'MyRfPlaylist', 'tok');
        $this->assertContains('/nextPlaylistInQueue', $this->rfPaths());

        // Network is up now. 30s later the main loop's retry reads the mode.
        $this->prefs('voting');
        $retryAt = $GLOBALS['modeLastAttemptAt'] + RF_MODE_RETRY_SECONDS;
        $this->assertTrue(rf_should_refresh_mode($GLOBALS['modeConfirmed'], $GLOBALS['emptyFetchSinceModeRead'], $retryAt, $GLOBALS['modeLastAttemptAt']));
        refreshViewerControlMode('tok');

        // Next song's fetch asks for the vote winner and queues it.
        $this->rfMock->clearRecordings();
        $GLOBALS['lastQueuedSequence'] = '';
        doNonInterruptStuff($this->fppStatus(), 1, $GLOBALS['viewerControlMode'], 0, 'MyRfPlaylist', 'tok');

        $this->assertContains('/highestVotedPlaylist', $this->rfPaths());
        $this->assertNotContains('/nextPlaylistInQueue', $this->rfPaths());
        $fppPaths = array_map(fn ($r) => $r['path'], $this->fppMock->getRecordings());
        $this->assertContains('/api/command/Insert Playlist After Current/MyRfPlaylist/18/18', $fppPaths);
    }

    public function testModeChangedInControlPanelIsPickedUpAfterAnEmptyFetch(): void {
        $this->prefs('jukebox');
        refreshViewerControlMode('tok');
        $this->rfMock->setRoute('/nextPlaylistInQueue', ['body' => ['nextPlaylist' => null, 'playlistIndex' => -1]]);

        doNonInterruptStuff($this->fppStatus(), 1, $GLOBALS['viewerControlMode'], 0, 'MyRfPlaylist', 'tok');

        $this->assertTrue($GLOBALS['emptyFetchSinceModeRead']);
        $this->assertFalse(rf_should_refresh_mode(true, true, $GLOBALS['modeLastAttemptAt'] + 60, $GLOBALS['modeLastAttemptAt']));
        $this->assertTrue(rf_should_refresh_mode(true, true, $GLOBALS['modeLastAttemptAt'] + RF_MODE_REFRESH_SECONDS, $GLOBALS['modeLastAttemptAt']));

        $this->prefs('voting');
        refreshViewerControlMode('tok');
        $this->assertSame('voting', $GLOBALS['viewerControlMode']);
        // A read resets the trigger so the next re-check needs a new empty fetch.
        $this->assertFalse($GLOBALS['emptyFetchSinceModeRead']);
    }

    // -------- Fetch and insert recording --------

    public function testFetchAndInsertAreRecordedInStatus(): void {
        $this->rfMock->setRoute('/highestVotedPlaylist', ['body' => ['winningPlaylist' => 'Your Idol', 'playlistIndex' => 18]]);
        $this->fppMock->setRoute('/api/command/Insert Playlist After Current*', ['body' => 'ok']);

        doNonInterruptStuff($this->fppStatus(), 1, 'voting', 0, 'MyRfPlaylist', 'tok');

        $status = $this->status();
        $this->assertSame('vote', $status['lastFetch']['kind']);
        $this->assertSame('Your Idol', $status['lastFetch']['sequence']);
        $this->assertFalse($status['lastFetch']['error']);
        $this->assertSame(['sequence' => 'Your Idol', 'ok' => true], array_intersect_key($status['lastInsert'], ['sequence' => 1, 'ok' => 1]));
        $this->assertIsInt($GLOBALS['lastControlFetchAt']);
        $this->assertFalse($GLOBALS['emptyFetchSinceModeRead']);
    }

    public function testFailedInsertIsRecorded(): void {
        $this->rfMock->setRoute('/nextPlaylistInQueue', ['body' => ['nextPlaylist' => 'Monster', 'playlistIndex' => 16]]);
        $this->fppMock->setRoute('/api/command/Insert Playlist After Current*', ['status' => 500, 'body' => 'no']);

        doNonInterruptStuff($this->fppStatus(), 1, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        $this->assertFalse($this->status()['lastInsert']['ok']);
    }

    public function testRepeatedEmptyFetchesDontRewriteTheStatusFile(): void {
        // Interrupt mode polls an empty queue about once a second.
        rf_record_control_fetch('request', (object) ['nextPlaylist' => null]);
        $firstWrite = $this->status()['lastFetch']['at'];
        @unlink($this->statusPath);

        rf_record_control_fetch('request', (object) ['nextPlaylist' => null]);

        $this->assertFileDoesNotExist($this->statusPath);
        // In-memory state still moves, so the heartbeat stays current.
        $this->assertGreaterThanOrEqual($firstWrite, $GLOBALS['lastControlFetchAt']);
        $this->assertTrue($GLOBALS['emptyFetchSinceModeRead']);
    }

    public function testFetchErrorIsDistinguishedFromNoVotes(): void {
        $this->rfMock->setRoute('/highestVotedPlaylist', ['status' => 500, 'body' => 'oops']);

        doNonInterruptStuff($this->fppStatus(), 1, 'voting', 0, 'MyRfPlaylist', 'tok');

        $this->assertTrue($this->status()['lastFetch']['error']);
        $this->assertNull($this->status()['lastFetch']['sequence']);
    }

    public function testInterruptModeRecordsFetchAndInsert(): void {
        $this->rfMock->setRoute('/highestVotedPlaylist', ['body' => ['winningPlaylist' => 'Your Idol', 'playlistIndex' => 18]]);
        $this->fppMock->setRoute('/api/command/Insert Playlist Immediate*', ['body' => 'ok']);

        doInterruptStuff($this->fppStatus(), 1, 'voting', 0, 'MyRfPlaylist', 'tok');

        $this->assertSame('Your Idol', $this->status()['lastFetch']['sequence']);
        $this->assertTrue($this->status()['lastInsert']['ok']);
    }

    // -------- Heartbeat body --------

    public function testHeartbeatSendsModeAndLastFetch(): void {
        $this->rfMock->setRoute('/fppHeartbeat', ['status' => 204, 'body' => '']);
        $this->prefs('voting');
        refreshViewerControlMode('tok');
        $GLOBALS['lastControlFetchAt'] = 1760000000;

        fppHeartbeat('tok');

        $beats = array_values(array_filter($this->rfMock->getRecordings(), fn ($r) => $r['path'] === '/fppHeartbeat'));
        $this->assertCount(1, $beats);
        $this->assertSame([
            'pluginVersion' => '2026.10.08.01',
            'viewerControlMode' => 'voting',
            'modeConfirmed' => true,
            'lastControlFetchAt' => 1760000000,
        ], json_decode($beats[0]['body'], true));
        $this->assertArrayHasKey('lastHeartbeat', $this->status());
    }

    // -------- Remote playlist check --------

    public function testCheckRemotePlaylist_missingPlaylistWarns(): void {
        $this->fppMock->setRoute('/api/playlists', ['body' => ['Main Show', 'Other']]);

        $this->assertFalse(checkRemotePlaylist('MyRfPlaylist'));

        $this->assertStringContainsString("Remote playlist 'MyRfPlaylist' was not found in FPP", $this->log());
        $this->assertNotNull($this->status()['remotePlaylistWarning']);
    }

    public function testCheckRemotePlaylist_warnsOnceAndClearsWhenThePlaylistAppears(): void {
        $this->fppMock->setRoute('/api/playlists', ['body' => ['Main Show']]);
        checkRemotePlaylist('MyRfPlaylist');
        checkRemotePlaylist('MyRfPlaylist');
        $this->assertSame(1, substr_count($this->log(), 'was not found in FPP'));

        $this->fppMock->setRoute('/api/playlists', ['body' => ['Main Show', 'MyRfPlaylist']]);

        $this->assertTrue(checkRemotePlaylist('MyRfPlaylist'));
        $this->assertNull($this->status()['remotePlaylistWarning']);
    }

    public function testCheckRemotePlaylist_presentPlaylistIsQuiet(): void {
        $this->fppMock->setRoute('/api/playlists', ['body' => ['MyRfPlaylist', 'Main Show']]);

        $this->assertTrue(checkRemotePlaylist('MyRfPlaylist'));

        $this->assertStringNotContainsString('WARNING', $this->log());
        $this->assertNull($this->status()['remotePlaylistWarning']);
    }

    public function testCheckRemotePlaylist_unsetPlaylistWarnsWithoutCallingFpp(): void {
        $this->assertFalse(checkRemotePlaylist(''));

        $this->assertStringContainsString('No remote playlist is set', $this->log());
        $this->assertCount(0, $this->fppMock->getRecordings());
    }

    public function testCheckRemotePlaylist_fppNotAnsweringIsQuiet(): void {
        $this->fppMock->setRoute('/api/playlists', ['status' => 500, 'body' => 'down']);

        // Unanswered: not a verdict, so the listener retries later.
        $this->assertNull(checkRemotePlaylist('MyRfPlaylist'));

        $this->assertStringNotContainsString('WARNING', $this->log());
        $this->assertArrayNotHasKey('remotePlaylistWarning', $this->status());
    }

    // -------- Status file --------

    public function testStatusFileIsValidJsonAndLeavesNoTempFile(): void {
        rf_status_update(['pluginVersion' => '2026.10.08.01']);

        $this->assertSame('2026.10.08.01', $this->status()['pluginVersion']);
        $this->assertIsInt($this->status()['updatedAt']);
        $this->assertFileDoesNotExist($this->statusPath . '.tmp');
    }

    public function testStatusUpdateNeverThrowsWhenTheFileCantBeWritten(): void {
        $GLOBALS['rfStatusFile'] = '/nonexistent-dir-rf/status.json';

        rf_status_update(['x' => 1]);
        rf_status_update(['x' => 2]);

        $this->assertSame(2, $GLOBALS['rfStatus']['x']);
        // Explained in the log once, not on every write.
        $this->assertSame(1, substr_count($this->log(), 'Could not write listener status'));
    }
}
