<?php

/**
 * Integration tests for the action layer in lib/listener_actions.php.
 * Each test points the wrappers at MockServer instances via $GLOBALS,
 * exercises one of the listener's high-level actions, and asserts on
 * what FPP and RF mocks recorded.
 *
 * Tests use requestFetchTime=1 so that sleep() at the end of doNonInterrupt
 * /doInterrupt is bounded; full-suite runtime stays under a minute.
 */
final class ListenerActionsTest extends IntegrationTestCase {

    private string $logFilePath;

    protected function setUp(): void {
        parent::setUp();

        // Send listener logs to a per-test temp file so logEntry doesn't
        // fall back to error_log (which would pollute test output).
        $this->logFilePath = sys_get_temp_dir() . '/rf-test-' . uniqid() . '.log';
        $GLOBALS['logFile'] = $this->logFilePath;
        $GLOBALS['verboseLogging'] = false;

        // Point the listener's HTTP wrappers at the mock servers.
        $GLOBALS['fppBaseUrl'] = $this->fppMock->getBaseUrl();
        $GLOBALS['pluginsApiPath'] = $this->rfMock->getBaseUrl();

        // Initialize listener state globals to safe defaults.
        $GLOBALS['lastQueuedSequence'] = '';
        $GLOBALS['lastQueuedTime'] = 0;
        $GLOBALS['remotePlaylist'] = 'MyRfPlaylist';
        $GLOBALS['currentlyPlayingInRF'] = '';
        $GLOBALS['nextScheduledInRF'] = '';

        // Reset the playlist details cache so prior tests can't bleed in.
        rf_playlist_cache_clear();
    }

    protected function tearDown(): void {
        @unlink($this->logFilePath);
        // Wipe globals we set so tests don't leak state.
        foreach (['logFile', 'verboseLogging', 'fppBaseUrl', 'pluginsApiPath',
                  'lastQueuedSequence', 'lastQueuedTime', 'remotePlaylist',
                  'currentlyPlayingInRF', 'nextScheduledInRF'] as $key) {
            unset($GLOBALS[$key]);
        }
        parent::tearDown();
    }

    private function makeFppStatus(string $sequence, int $secondsRemaining = 1, ?string $playlist = 'OtherPlaylist'): stdClass {
        $s = new stdClass();
        $s->status_name = 'playing';
        $s->current_sequence = $sequence;
        $s->seconds_remaining = $secondsRemaining;
        if ($playlist !== null) {
            $s->current_playlist = new stdClass();
            $s->current_playlist->playlist = $playlist;
        }
        return $s;
    }

    private function pathsRecordedOn(MockServer $mock): array {
        return array_map(function ($r) { return $r['path']; }, $mock->getRecordings());
    }

    // -------- doNonInterruptStuff --------

    public function testDoNonInterrupt_skipsWhenSecondsRemainingHigh(): void {
        $status = $this->makeFppStatus('current.fseq', 10);
        doNonInterruptStuff($status, 1, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        // Plenty of time left → no RF fetch.
        $this->assertCount(0, $this->rfMock->getRecordings());
        $this->assertCount(0, $this->fppMock->getRecordings());
    }

    public function testDoNonInterrupt_jukeboxModeFetchesAndQueuesWhenNextAvailable(): void {
        $this->rfMock->setRoute('/nextPlaylistInQueue', [
            'body' => ['nextPlaylist' => 'happy.fseq', 'playlistIndex' => 4],
        ]);
        $this->fppMock->setRoute('/api/command/Insert Playlist After Current*', ['body' => 'ok']);

        $status = $this->makeFppStatus('current.fseq', 0);
        doNonInterruptStuff($status, 1, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        $this->assertContains('/nextPlaylistInQueue', $this->pathsRecordedOn($this->rfMock));
        $fppPaths = $this->pathsRecordedOn($this->fppMock);
        $this->assertCount(1, $fppPaths);
        $this->assertSame('/api/command/Insert Playlist After Current/MyRfPlaylist/4/4', $fppPaths[0]);
        // Dedup state updated.
        $this->assertSame('current', $GLOBALS['lastQueuedSequence']);
        $this->assertGreaterThan(0, $GLOBALS['lastQueuedTime']);
    }

    public function testDoNonInterrupt_jukeboxModeUpdatesStateEvenWithNoNext(): void {
        $this->rfMock->setRoute('/nextPlaylistInQueue', [
            'body' => ['nextPlaylist' => null, 'playlistIndex' => null],
        ]);

        $status = $this->makeFppStatus('current.fseq', 0);
        doNonInterruptStuff($status, 1, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        // Still updates dedup state to suppress re-checks for this sequence.
        $this->assertSame('current', $GLOBALS['lastQueuedSequence']);
        $this->assertCount(0, $this->fppMock->getRecordings());
    }

    public function testDoNonInterrupt_votingModeCallsHighestVoted(): void {
        $this->rfMock->setRoute('/highestVotedPlaylist', [
            'body' => ['winningPlaylist' => 'winner.fseq', 'playlistIndex' => 7],
        ]);
        $this->fppMock->setRoute('/api/command/Insert Playlist After Current*', ['body' => 'ok']);

        $status = $this->makeFppStatus('current.fseq', 0);
        doNonInterruptStuff($status, 1, 'voting', 0, 'MyRfPlaylist', 'tok');

        $this->assertContains('/highestVotedPlaylist', $this->pathsRecordedOn($this->rfMock));
        $fppPaths = $this->pathsRecordedOn($this->fppMock);
        $this->assertSame('/api/command/Insert Playlist After Current/MyRfPlaylist/7/7', $fppPaths[0]);
    }

    public function testDoNonInterrupt_skipsWhenWithinDedupWindow(): void {
        // Just queued for "current" 1 second ago; window is 1+0+2=3 → skip.
        $GLOBALS['lastQueuedSequence'] = 'current';
        $GLOBALS['lastQueuedTime'] = time() - 1;

        $status = $this->makeFppStatus('current.fseq', 0);
        doNonInterruptStuff($status, 1, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        $this->assertCount(0, $this->rfMock->getRecordings());
        $this->assertCount(0, $this->fppMock->getRecordings());
    }

    // -------- doInterruptStuff --------

    public function testDoInterrupt_returnsEarlyWhenCurrentPlaylistNull(): void {
        $status = $this->makeFppStatus('current.fseq', 0, null);
        doInterruptStuff($status, 1, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        $this->assertCount(0, $this->rfMock->getRecordings());
        $this->assertCount(0, $this->fppMock->getRecordings());
    }

    public function testDoInterrupt_fallsBackToNonInterruptOnRemotePlaylist(): void {
        $this->rfMock->setRoute('/nextPlaylistInQueue', [
            'body' => ['nextPlaylist' => 'happy.fseq', 'playlistIndex' => 4],
        ]);
        $this->fppMock->setRoute('/api/command/Insert Playlist After Current*', ['body' => 'ok']);

        // Currently playing the user's RF playlist → falls through to
        // non-interrupt logic, which uses Insert After Current.
        $status = $this->makeFppStatus('a.fseq', 0, 'MyRfPlaylist');
        doInterruptStuff($status, 1, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        $fppPaths = $this->pathsRecordedOn($this->fppMock);
        $this->assertNotEmpty($fppPaths);
        $this->assertStringContainsString('Insert Playlist After Current', $fppPaths[0]);
    }

    public function testDoInterrupt_jukeboxModeQueuesImmediate(): void {
        $this->rfMock->setRoute('/nextPlaylistInQueue', [
            'body' => ['nextPlaylist' => 'urgent.fseq', 'playlistIndex' => 9],
        ]);
        $this->fppMock->setRoute('/api/command/Insert Playlist Immediate*', ['body' => 'ok']);

        $status = $this->makeFppStatus('a.fseq', 30, 'OtherPlaylist');
        doInterruptStuff($status, 1, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        $fppPaths = $this->pathsRecordedOn($this->fppMock);
        $this->assertSame('/api/command/Insert Playlist Immediate/MyRfPlaylist/9/9', $fppPaths[0]);
        // Interrupt mode tracks the WINNING sequence as lastQueuedSequence,
        // not the currently-playing one.
        $this->assertSame('urgent.fseq', $GLOBALS['lastQueuedSequence']);
    }

    public function testDoInterrupt_votingModeQueuesImmediate(): void {
        $this->rfMock->setRoute('/highestVotedPlaylist', [
            'body' => ['winningPlaylist' => 'win.fseq', 'playlistIndex' => 2],
        ]);
        $this->fppMock->setRoute('/api/command/Insert Playlist Immediate*', ['body' => 'ok']);

        $status = $this->makeFppStatus('a.fseq', 30, 'OtherPlaylist');
        doInterruptStuff($status, 1, 'voting', 0, 'MyRfPlaylist', 'tok');

        $fppPaths = $this->pathsRecordedOn($this->fppMock);
        $this->assertSame('/api/command/Insert Playlist Immediate/MyRfPlaylist/2/2', $fppPaths[0]);
    }

    public function testDoInterrupt_skipsWhenWithinDedupWindow(): void {
        $GLOBALS['lastQueuedTime'] = time() - 1;  // window is 3s
        $status = $this->makeFppStatus('a.fseq', 30, 'OtherPlaylist');
        doInterruptStuff($status, 1, 'jukebox', 0, 'MyRfPlaylist', 'tok');

        $this->assertCount(0, $this->rfMock->getRecordings());
        $this->assertCount(0, $this->fppMock->getRecordings());
    }

    // -------- updateCurrentlyPlaying / updateNextScheduledSequence --------

    public function testUpdateCurrentlyPlaying_postsWhenStateDiffers(): void {
        $this->rfMock->setRoute('/updateWhatsPlaying', ['body' => ['ok' => true]]);
        updateCurrentlyPlaying('new-song', 'old-song', 'tok');

        $this->assertContains('/updateWhatsPlaying', $this->pathsRecordedOn($this->rfMock));
        $this->assertSame('new-song', $GLOBALS['currentlyPlayingInRF']);
    }

    public function testUpdateCurrentlyPlaying_skipsWhenStateMatches(): void {
        updateCurrentlyPlaying('same', 'same', 'tok');
        $this->assertCount(0, $this->rfMock->getRecordings());
    }

    public function testUpdateNextScheduledSequence_skipsWhenCurrentPlaylistMissing(): void {
        $status = new stdClass();  // no current_playlist
        updateNextScheduledSequence($status, 'a', '', 'tok');
        $this->assertCount(0, $this->rfMock->getRecordings());
        $this->assertCount(0, $this->fppMock->getRecordings());
    }

    public function testUpdateNextScheduledSequence_postsNextWhenAvailable(): void {
        $this->fppMock->setRoute('/api/playlist/OtherPlaylist', [
            'body' => [
                'mainPlaylist' => [
                    ['sequenceName' => 'a.fseq'],
                    ['sequenceName' => 'b.fseq'],
                ],
            ],
        ]);
        $this->rfMock->setRoute('/updateNextScheduledSequence', ['body' => ['ok' => true]]);

        $status = $this->makeFppStatus('a.fseq', 30);
        updateNextScheduledSequence($status, 'a', '', 'tok');

        $this->assertContains('/updateNextScheduledSequence', $this->pathsRecordedOn($this->rfMock));
        $this->assertSame('b', $GLOBALS['nextScheduledInRF']);
    }

    public function testUpdateNextScheduledSequence_cachesPlaylistDetailsAcrossCalls(): void {
        // Perf 2.2: playlist details cached for 60s. Three back-to-back
        // calls with the same playlist should produce ONE FPP fetch and
        // (depending on next-scheduled state changes) up to one RF post.
        $this->fppMock->setRoute('/api/playlist/OtherPlaylist', [
            'body' => [
                'mainPlaylist' => [
                    ['sequenceName' => 'a.fseq'],
                    ['sequenceName' => 'b.fseq'],
                    ['sequenceName' => 'c.fseq'],
                ],
            ],
        ]);
        $this->rfMock->setRoute('/updateNextScheduledSequence', ['body' => ['ok' => true]]);

        $status = $this->makeFppStatus('a.fseq', 30, 'OtherPlaylist');

        // Call three times in a row.
        updateNextScheduledSequence($status, 'a', '', 'tok');
        updateNextScheduledSequence($status, 'a', 'b', 'tok');
        updateNextScheduledSequence($status, 'a', 'b', 'tok');

        $fppHits = array_filter($this->pathsRecordedOn($this->fppMock), function ($p) {
            return strpos($p, '/api/playlist/') === 0;
        });
        $this->assertCount(1, $fppHits, 'FPP playlist endpoint should be hit once across 3 calls within TTL');
    }

    public function testUpdateNextScheduledSequence_usesFppIndexForRepeatedSequence(): void {
        $this->fppMock->setRoute('/api/playlist/OtherPlaylist', [
            'body' => ['mainPlaylist' => $this->sequenceEntries(['a', 'x', 'b', 'x', 'c'])],
        ]);
        $this->rfMock->setRoute('/updateNextScheduledSequence', ['body' => ['ok' => true]]);

        // Second 'x' is playing.
        updateNextScheduledSequence($this->playingStatus('x', 4, 5), 'x', '', 'tok');

        $this->assertSame(['c'], $this->nextScheduledPosted());
        $this->assertSame('c', $GLOBALS['nextScheduledInRF']);
    }

    /**
     * fppd flattens sub-playlists into current_playlist.index, so the JSON has
     * to be fetched flattened the same way for the index to line up.
     */
    public function testUpdateNextScheduledSequence_requestsPlaylistWithSubPlaylistsMerged(): void {
        $this->fppMock->setRoute('/api/playlist/OtherPlaylist', [
            'body' => ['mainPlaylist' => $this->sequenceEntries(['a', 'b'])],
        ]);
        $this->rfMock->setRoute('/updateNextScheduledSequence', ['body' => ['ok' => true]]);

        updateNextScheduledSequence($this->playingStatus('a', 1, 2), 'a', '', 'tok');

        $recordings = $this->fppMock->getRecordings();
        $this->assertCount(1, $recordings);
        $this->assertSame('mergeSubs=1', $recordings[0]['query']);
    }

    // -------- syncShowStateToRf --------

    /**
     * Reported bug: stopping the show and starting it again from the top left
     * "next scheduled" blank for the whole first song. Going idle cleared it
     * in RF, but the listener still remembered the pre-stop value, and the
     * first song computed that same value, so the repost was deduped away.
     */
    public function testSyncShowState_republishesStateAfterStopAndRestartAtSamePosition(): void {
        $this->fppMock->setRoute('/api/playlist/OtherPlaylist', [
            'body' => ['mainPlaylist' => $this->sequenceEntries(['a', 'b', 'c'])],
        ]);
        $this->rfMock->setRoute('/updateWhatsPlaying', ['body' => ['ok' => true]]);
        $this->rfMock->setRoute('/updateNextScheduledSequence', ['body' => ['ok' => true]]);

        $cleared = syncShowStateToRf($this->playingStatus('a', 1, 3), 'tok', false);
        $this->assertFalse($cleared);
        $this->assertSame(['a'], $this->whatsPlayingPosted());
        $this->assertSame(['b'], $this->nextScheduledPosted());

        // Show stopped: both cleared in RF, once.
        $this->rfMock->clearRecordings();
        $cleared = syncShowStateToRf($this->idleStatus(), 'tok', $cleared);
        $cleared = syncShowStateToRf($this->idleStatus(), 'tok', $cleared);
        $this->assertTrue($cleared);
        $this->assertSame([''], $this->whatsPlayingPosted());
        $this->assertSame([''], $this->nextScheduledPosted());

        // Restarted from the top: RF must get both values again.
        $this->rfMock->clearRecordings();
        $cleared = syncShowStateToRf($this->playingStatus('a', 1, 3), 'tok', $cleared);
        $this->assertFalse($cleared);
        $this->assertSame(['a'], $this->whatsPlayingPosted());
        $this->assertSame(['b'], $this->nextScheduledPosted());
    }

    public function testSyncShowState_doesNotRepostUnchangedStateWhilePlaying(): void {
        $this->fppMock->setRoute('/api/playlist/OtherPlaylist', [
            'body' => ['mainPlaylist' => $this->sequenceEntries(['a', 'b'])],
        ]);
        $this->rfMock->setRoute('/updateWhatsPlaying', ['body' => ['ok' => true]]);
        $this->rfMock->setRoute('/updateNextScheduledSequence', ['body' => ['ok' => true]]);

        $status = $this->playingStatus('a', 1, 2);
        $cleared = syncShowStateToRf($status, 'tok', false);
        $cleared = syncShowStateToRf($status, 'tok', $cleared);
        syncShowStateToRf($status, 'tok', $cleared);

        $this->assertSame(['a'], $this->whatsPlayingPosted());
        $this->assertSame(['b'], $this->nextScheduledPosted());
    }

    /**
     * Whatever the listener remembers as "last sent" must match what RF was
     * told, or its dedup suppresses a post RF needs. After every step of a
     * show with stops, repeats and a restart, the remembered values must
     * equal the most recent values actually posted.
     */
    public function testSyncShowState_rememberedValuesAlwaysMatchLastPosted(): void {
        $this->fppMock->setRoute('/api/playlist/OtherPlaylist', [
            'body' => ['mainPlaylist' => $this->sequenceEntries(['a', 'x', 'b', 'x'])],
        ]);
        $this->rfMock->setRoute('/updateWhatsPlaying', ['body' => ['ok' => true]]);
        $this->rfMock->setRoute('/updateNextScheduledSequence', ['body' => ['ok' => true]]);

        $steps = [
            $this->playingStatus('a', 1, 4),
            $this->playingStatus('x', 2, 4),
            $this->idleStatus(),
            $this->playingStatus('a', 1, 4),
            $this->playingStatus('x', 2, 4),
            $this->playingStatus('b', 3, 4),
            $this->playingStatus('x', 4, 4),
            $this->idleStatus(),
            $this->playingStatus('x', 4, 4),
        ];
        $cleared = false;
        foreach ($steps as $i => $status) {
            $cleared = syncShowStateToRf($status, 'tok', $cleared);
            $playing = $this->whatsPlayingPosted();
            $next = $this->nextScheduledPosted();
            $this->assertSame(trim($GLOBALS['currentlyPlayingInRF']), end($playing), "step $i: currently playing");
            $this->assertSame(trim($GLOBALS['nextScheduledInRF']), end($next), "step $i: next scheduled");
        }
    }

    // -------- helpers for playlist-position scenarios --------

    private function sequenceEntries(array $names): array {
        return array_map(function ($n) {
            return ['type' => 'sequence', 'sequenceName' => $n . '.fseq'];
        }, $names);
    }

    /** FPP status mid-playlist; index is 1-based, as FPP reports it. */
    private function playingStatus(string $sequence, int $index, int $count, string $playlist = 'OtherPlaylist'): stdClass {
        $s = $this->makeFppStatus($sequence . '.fseq', 30, $playlist);
        $s->current_playlist->index = (string) $index;
        $s->current_playlist->count = (string) $count;
        return $s;
    }

    /** FPP status between shows, per Playlist::GetCurrentStatus. */
    private function idleStatus(): stdClass {
        return (object) [
            'status_name' => 'idle',
            'current_sequence' => '',
            'current_song' => '',
            'seconds_remaining' => '0',
            'current_playlist' => (object) ['playlist' => '', 'index' => '0', 'count' => '0'],
        ];
    }

    private function postedValues(string $path, string $field): array {
        $values = [];
        foreach ($this->rfMock->getRecordings() as $r) {
            if ($r['path'] === $path) {
                $values[] = json_decode($r['body'], true)[$field];
            }
        }
        return $values;
    }

    private function whatsPlayingPosted(): array {
        return $this->postedValues('/updateWhatsPlaying', 'playlist');
    }

    private function nextScheduledPosted(): array {
        return $this->postedValues('/updateNextScheduledSequence', 'sequence');
    }

    public function testUpdateNextScheduledSequence_skipsFppFetchWhenPlayingRemotePlaylist(): void {
        // Perf 2.1 optimization: when current playlist == remote playlist,
        // we skip the FPP /api/playlist fetch entirely (it would have been
        // wasted — rf_decide_next_scheduled_update returns null in that
        // case anyway). Asserts the FPP mock receives ZERO requests.
        $this->fppMock->setRoute('/api/playlist/MyRfPlaylist', [
            'body' => [
                'mainPlaylist' => [
                    ['sequenceName' => 'a.fseq'],
                    ['sequenceName' => 'b.fseq'],
                ],
            ],
        ]);

        $status = $this->makeFppStatus('a.fseq', 30, 'MyRfPlaylist');
        updateNextScheduledSequence($status, 'a', '', 'tok');

        // No FPP fetch, no RF post.
        $this->assertCount(0, $this->fppMock->getRecordings(), 'FPP playlist endpoint should NOT have been hit when on remote playlist');
        $this->assertCount(0, $this->rfMock->getRecordings());
    }
}
