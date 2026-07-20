<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for pure-logic functions in lib/listener_logic.php.
 * These run anywhere PHP runs — no FPP, no network, no filesystem.
 */
final class ListenerLogicTest extends TestCase {

    private function seq(string $name): stdClass {
        $o = new stdClass();
        $o->sequenceName = $name;
        return $o;
    }

    // -------- rf_get_next_sequence --------

    public function testGetNextSequence_normalCase(): void {
        $playlist = [$this->seq('a.fseq'), $this->seq('b.fseq'), $this->seq('c.fseq')];
        $this->assertSame('b', rf_get_next_sequence($playlist, 'a'));
        $this->assertSame('c', rf_get_next_sequence($playlist, 'b'));
    }

    public function testGetNextSequence_lastSequenceWrapsToFirst(): void {
        $playlist = [$this->seq('a.fseq'), $this->seq('b.fseq'), $this->seq('c.fseq')];
        $this->assertSame('a', rf_get_next_sequence($playlist, 'c'));
    }

    public function testGetNextSequence_currentNotFoundReturnsEmpty(): void {
        $playlist = [$this->seq('a.fseq'), $this->seq('b.fseq')];
        $this->assertSame('', rf_get_next_sequence($playlist, 'nonexistent'));
    }

    public function testGetNextSequence_emptyPlaylistReturnsEmpty(): void {
        $this->assertSame('', rf_get_next_sequence([], 'anything'));
    }

    /**
     * Regression test for the missing-break bug fixed in the cleanup branch.
     * Before the fix, this would return 'd' (the neighbor of the SECOND 'b'
     * occurrence — the loop's last match). After the fix, it returns 'c'
     * (the neighbor of the FIRST 'b' occurrence).
     */
    public function testGetNextSequence_duplicateSequenceReturnsFirstMatchNeighbor(): void {
        $playlist = [
            $this->seq('a.fseq'),
            $this->seq('b.fseq'),
            $this->seq('c.fseq'),
            $this->seq('b.fseq'),  // duplicate
            $this->seq('d.fseq'),
        ];
        $this->assertSame('c', rf_get_next_sequence($playlist, 'b'));
    }

    public function testGetNextSequence_handlesPathPrefixedNames(): void {
        $playlist = [$this->seq('subdir/a.fseq'), $this->seq('subdir/b.fseq')];
        $this->assertSame('b', rf_get_next_sequence($playlist, 'a'));
    }

    public function testGetNextSequence_scansPastTrailingPauseWithWrap(): void {
        $pause = new stdClass();
        $playlist = [$this->seq('a.fseq'), $this->seq('b.fseq'), $pause];
        // current is the last real sequence; next wraps past the trailing pause to 'a'
        $this->assertSame('a', rf_get_next_sequence($playlist, 'b'));
    }

    /**
     * When the immediate next item has no sequenceName (a pause or command-type
     * playlist entry), the function scans ahead to the next real sequence rather
     * than giving up. Fixes the prior behavior where [sequence, command, sequence]
     * reported no next-scheduled while playing the first sequence (pause-scan fix).
     */
    public function testGetNextSequence_scansPastNextItemMissingSequenceName(): void {
        $missing = new stdClass();  // no sequenceName property
        $playlist = [$this->seq('a.fseq'), $missing, $this->seq('b.fseq')];
        $this->assertSame('b', rf_get_next_sequence($playlist, 'a'));
    }

    public function testGetNextSequence_singleSequenceWrapsToItself(): void {
        $playlist = [$this->seq('only.fseq')];
        $this->assertSame('only', rf_get_next_sequence($playlist, 'only'));
    }

    // -------- rf_clamp_status_check_time --------

    /**
     * Regression test for the clamping fix in the cleanup branch.
     * Before the fix, fppStatusCheckTime=0 caused usleep(0) busy-loops.
     */
    public function testClampStatusCheckTime_zeroIsClampedToMinimum(): void {
        $this->assertSame(0.1, rf_clamp_status_check_time(0));
        $this->assertSame(0.1, rf_clamp_status_check_time('0'));
    }

    public function testClampStatusCheckTime_negativeIsClampedToMinimum(): void {
        $this->assertSame(0.1, rf_clamp_status_check_time(-1));
        $this->assertSame(0.1, rf_clamp_status_check_time(-0.5));
    }

    public function testClampStatusCheckTime_belowMinimumIsClamped(): void {
        $this->assertSame(0.1, rf_clamp_status_check_time(0.05));
    }

    public function testClampStatusCheckTime_atMinimumIsKept(): void {
        $this->assertSame(0.1, rf_clamp_status_check_time(0.1));
    }

    public function testClampStatusCheckTime_normalValuesPassThrough(): void {
        $this->assertSame(0.5, rf_clamp_status_check_time(0.5));
        $this->assertSame(1.0, rf_clamp_status_check_time(1));
        $this->assertSame(3.0, rf_clamp_status_check_time('3'));
    }

    public function testClampStatusCheckTime_garbageInputClampsToMinimum(): void {
        // floatval('abc') === 0.0, which is < 0.1
        $this->assertSame(0.1, rf_clamp_status_check_time('not a number'));
    }

    // -------- rf_decide_currently_playing_update --------

    public function testDecideCurrentlyPlayingUpdate_noChangeReturnsNull(): void {
        $this->assertNull(rf_decide_currently_playing_update('song', 'song'));
        $this->assertNull(rf_decide_currently_playing_update('', ''));
    }

    public function testDecideCurrentlyPlayingUpdate_changeReturnsNewValue(): void {
        $this->assertSame('song', rf_decide_currently_playing_update('song', ''));
        $this->assertSame('song-b', rf_decide_currently_playing_update('song-b', 'song-a'));
    }

    public function testDecideCurrentlyPlayingUpdate_clearsToSpaceSentinel(): void {
        // Listener uses a single space to clear "currently playing" in RF.
        $this->assertSame(' ', rf_decide_currently_playing_update(' ', 'song'));
    }

    // -------- rf_decide_next_scheduled_update --------

    private function playlistDetails(array $sequenceNames): stdClass {
        $details = new stdClass();
        $details->mainPlaylist = array_map(function ($name) {
            $o = new stdClass();
            $o->sequenceName = $name;
            return $o;
        }, $sequenceNames);
        return $details;
    }

    public function testDecideNextScheduledUpdate_postsNextSequence(): void {
        $details = $this->playlistDetails(['a.fseq', 'b.fseq', 'c.fseq']);
        $result = rf_decide_next_scheduled_update($details, 'MyShow', 'a', '', 'OtherPlaylist');
        $this->assertSame('b', $result);
    }

    public function testDecideNextScheduledUpdate_skipsWhenUnchanged(): void {
        $details = $this->playlistDetails(['a.fseq', 'b.fseq']);
        // Already told RF that "b" is next; computed value matches → skip.
        $this->assertNull(rf_decide_next_scheduled_update($details, 'MyShow', 'a', 'b', 'OtherPlaylist'));
    }

    public function testDecideNextScheduledUpdate_skipsWhenPlayingRemotePlaylist(): void {
        $details = $this->playlistDetails(['a.fseq', 'b.fseq']);
        // FPP is currently playing the user's RF playlist → RF owns sequencing.
        $this->assertNull(rf_decide_next_scheduled_update($details, 'RfPlaylist', 'a', '', 'RfPlaylist'));
    }

    public function testDecideNextScheduledUpdate_skipsOnNullPlaylistDetails(): void {
        $this->assertNull(rf_decide_next_scheduled_update(null, 'MyShow', 'a', '', 'Other'));
    }

    public function testDecideNextScheduledUpdate_skipsWhenMainPlaylistMissing(): void {
        $details = new stdClass();  // no mainPlaylist property
        $this->assertNull(rf_decide_next_scheduled_update($details, 'MyShow', 'a', '', 'Other'));
    }

    public function testDecideNextScheduledUpdate_skipsOnEmptyMainPlaylist(): void {
        $details = new stdClass();
        $details->mainPlaylist = [];
        $this->assertNull(rf_decide_next_scheduled_update($details, 'MyShow', 'a', '', 'Other'));
    }

    public function testDecideNextScheduledUpdate_skipsWhenMainPlaylistNotArray(): void {
        $details = new stdClass();
        $details->mainPlaylist = 'unexpected string';
        $this->assertNull(rf_decide_next_scheduled_update($details, 'MyShow', 'a', '', 'Other'));
    }

    public function testDecideNextScheduledUpdate_wrapsAtEndOfPlaylist(): void {
        $details = $this->playlistDetails(['a.fseq', 'b.fseq', 'c.fseq']);
        // Currently playing 'c' (last item) → wraps to 'a'.
        $this->assertSame('a', rf_decide_next_scheduled_update($details, 'MyShow', 'c', '', 'Other'));
    }

    // -------- rf_extract_currently_playing --------

    private function fppStatus(?string $sequence, ?string $song = null): stdClass {
        $s = new stdClass();
        if ($sequence !== null) $s->current_sequence = $sequence;
        if ($song !== null) $s->current_song = $song;
        return $s;
    }

    public function testExtractCurrentlyPlaying_usesCurrentSequenceWhenSet(): void {
        $this->assertSame('song-name', rf_extract_currently_playing($this->fppStatus('song-name.fseq')));
    }

    public function testExtractCurrentlyPlaying_fallsBackToCurrentSong(): void {
        $this->assertSame('media', rf_extract_currently_playing($this->fppStatus('', 'media.mp3')));
    }

    public function testExtractCurrentlyPlaying_returnsEmptyWhenBothMissing(): void {
        $this->assertSame('', rf_extract_currently_playing(new stdClass()));
    }

    public function testExtractCurrentlyPlaying_returnsEmptyWhenBothEmpty(): void {
        $this->assertSame('', rf_extract_currently_playing($this->fppStatus('', '')));
    }

    public function testExtractCurrentlyPlaying_stripsPathAndExtension(): void {
        $this->assertSame('a', rf_extract_currently_playing($this->fppStatus('subdir/a.fseq')));
    }

    // -------- rf_should_skip_non_interrupt_check --------

    public function testSkipNonInterrupt_falseWhenSequenceDiffers(): void {
        // Different sequence → never skip, regardless of timing.
        $this->assertFalse(rf_should_skip_non_interrupt_check('a', 'b', 1000, 999, 3, 0));
    }

    public function testSkipNonInterrupt_trueWhenSameSequenceWithinWindow(): void {
        // Same sequence, queued 2s ago, window is 3+0+2=5 → skip.
        $this->assertTrue(rf_should_skip_non_interrupt_check('a', 'a', 1002, 1000, 3, 0));
    }

    public function testSkipNonInterrupt_falseWhenSameSequenceOutsideWindow(): void {
        // Same sequence but queued 10s ago, window 5 → re-check.
        $this->assertFalse(rf_should_skip_non_interrupt_check('a', 'a', 1010, 1000, 3, 0));
    }

    public function testSkipNonInterrupt_windowIncludesAdditionalWaitTime(): void {
        // requestFetchTime=3, additionalWaitTime=5, total window = 10.
        // 8s elapsed → still inside window → skip.
        $this->assertTrue(rf_should_skip_non_interrupt_check('a', 'a', 1008, 1000, 3, 5));
        // 11s elapsed → outside → re-check.
        $this->assertFalse(rf_should_skip_non_interrupt_check('a', 'a', 1011, 1000, 3, 5));
    }

    /**
     * Documents an existing bug surfaced by the audit. With duplicate
     * sequence names back-to-back in the playlist, the second occurrence
     * is incorrectly suppressed because dedup keys on name only. The
     * perf branch fixes this by keying on (playlist, position, start_time).
     */
    public function testSkipNonInterrupt_duplicateSequenceBack2Back_existingBuggyBehavior(): void {
        // 'a' just queued, FPP rolls into the SECOND 'a' in the playlist.
        // Within the window, dedup says skip — but it shouldn't, because
        // this is a different play of the same-named sequence.
        $this->assertTrue(rf_should_skip_non_interrupt_check('a', 'a', 1001, 1000, 3, 0));
    }

    // -------- rf_should_skip_interrupt_check --------

    public function testSkipInterrupt_trueWithinWindow(): void {
        $this->assertTrue(rf_should_skip_interrupt_check(1002, 1000, 3, 0));
    }

    public function testSkipInterrupt_falseOutsideWindow(): void {
        $this->assertFalse(rf_should_skip_interrupt_check(1010, 1000, 3, 0));
    }

    public function testSkipInterrupt_doesNotKeyOnSequenceName(): void {
        // Unlike non-interrupt mode, interrupt mode has no name parameter —
        // any recent interrupt blocks another regardless of sequence identity.
        $this->assertTrue(rf_should_skip_interrupt_check(1001, 1000, 3, 0));
    }

    // -------- rf_should_fetch_now --------

    public function testShouldFetchNow_trueWhenSecondsRemainingBelowFetchTime(): void {
        $this->assertTrue(rf_should_fetch_now(2, 3));
        $this->assertTrue(rf_should_fetch_now(0, 3));
    }

    public function testShouldFetchNow_falseWhenSecondsRemainingAtOrAboveFetchTime(): void {
        $this->assertFalse(rf_should_fetch_now(3, 3));
        $this->assertFalse(rf_should_fetch_now(10, 3));
    }

    // -------- rf_next_poll_seconds (idle backoff) --------

    public function testNextPollSeconds_returnsConfiguredWhenPlaying(): void {
        $this->assertSame(1.0, rf_next_poll_seconds('playing', 1.0));
        $this->assertSame(0.5, rf_next_poll_seconds('playing', 0.5));
    }

    public function testNextPollSeconds_returnsConfiguredForOtherStates(): void {
        // Any non-"idle" state uses the configured value.
        $this->assertSame(1.0, rf_next_poll_seconds('paused', 1.0));
        $this->assertSame(1.0, rf_next_poll_seconds('stopping', 1.0));
        $this->assertSame(1.0, rf_next_poll_seconds('', 1.0));
    }

    public function testNextPollSeconds_backsOffWhenIdle(): void {
        // FPP between scheduled blocks → no point polling fast.
        $this->assertSame(5.0, rf_next_poll_seconds('idle', 1.0));
        $this->assertSame(5.0, rf_next_poll_seconds('idle', 0.5));
    }

    public function testNextPollSeconds_idleNeverFasterThanConfigured(): void {
        // If user configured slower than 5s, idle keeps their value —
        // backoff should never poll harder than they asked for.
        $this->assertSame(10.0, rf_next_poll_seconds('idle', 10.0));
        $this->assertSame(5.0, rf_next_poll_seconds('idle', 5.0));
    }

    // -------- rf_playlist_cache_* (perf 2.2) --------

    private function fakePlaylistDetails(string $marker): stdClass {
        $o = new stdClass();
        $o->mainPlaylist = [(object) ['sequenceName' => $marker]];
        return $o;
    }

    public function testPlaylistCache_missReturnsNull(): void {
        rf_playlist_cache_clear();
        $this->assertNull(rf_playlist_cache_get('NoSuchPlaylist', 100.0, 60.0));
    }

    public function testPlaylistCache_hitWithinTtl(): void {
        rf_playlist_cache_clear();
        $details = $this->fakePlaylistDetails('a.fseq');
        rf_playlist_cache_put('MyShow', $details, 100.0);
        $hit = rf_playlist_cache_get('MyShow', 130.0, 60.0);
        $this->assertNotNull($hit);
        $this->assertSame('a.fseq', $hit->mainPlaylist[0]->sequenceName);
    }

    public function testPlaylistCache_expiredEntryReturnsNull(): void {
        rf_playlist_cache_clear();
        rf_playlist_cache_put('MyShow', $this->fakePlaylistDetails('a.fseq'), 100.0);
        // 61s later → past 60s TTL.
        $this->assertNull(rf_playlist_cache_get('MyShow', 161.0, 60.0));
    }

    public function testPlaylistCache_atTtlBoundaryIsHit(): void {
        rf_playlist_cache_clear();
        rf_playlist_cache_put('MyShow', $this->fakePlaylistDetails('a.fseq'), 100.0);
        // Exactly at TTL → still a hit (not strictly greater).
        $this->assertNotNull(rf_playlist_cache_get('MyShow', 160.0, 60.0));
    }

    public function testPlaylistCache_separateKeysIsolated(): void {
        rf_playlist_cache_clear();
        rf_playlist_cache_put('Show1', $this->fakePlaylistDetails('a.fseq'), 100.0);
        rf_playlist_cache_put('Show2', $this->fakePlaylistDetails('b.fseq'), 100.0);
        $this->assertSame('a.fseq', rf_playlist_cache_get('Show1', 100.0, 60.0)->mainPlaylist[0]->sequenceName);
        $this->assertSame('b.fseq', rf_playlist_cache_get('Show2', 100.0, 60.0)->mainPlaylist[0]->sequenceName);
    }

    public function testPlaylistCache_putOverwrites(): void {
        rf_playlist_cache_clear();
        rf_playlist_cache_put('MyShow', $this->fakePlaylistDetails('old'), 100.0);
        rf_playlist_cache_put('MyShow', $this->fakePlaylistDetails('new'), 200.0);
        $this->assertSame('new', rf_playlist_cache_get('MyShow', 200.0, 60.0)->mainPlaylist[0]->sequenceName);
    }

    public function testPlaylistCache_clearRemovesEverything(): void {
        rf_playlist_cache_put('A', $this->fakePlaylistDetails('a'), 100.0);
        rf_playlist_cache_put('B', $this->fakePlaylistDetails('b'), 100.0);
        rf_playlist_cache_clear();
        $this->assertNull(rf_playlist_cache_get('A', 100.0, 60.0));
        $this->assertNull(rf_playlist_cache_get('B', 100.0, 60.0));
    }

    // -------- rf_ini_should_reparse / rf_ini_current_mtime (perf 2.4) --------

    public function testIniShouldReparse_trueOnFirstCall(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'rf-ini-test-');
        file_put_contents($tmp, "key = \"value\"\n");
        try {
            $this->assertTrue(rf_ini_should_reparse($tmp, null));
        } finally {
            @unlink($tmp);
        }
    }

    public function testIniShouldReparse_falseWhenMtimeUnchanged(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'rf-ini-test-');
        file_put_contents($tmp, "key = \"value\"\n");
        try {
            $mtime = rf_ini_current_mtime($tmp);
            $this->assertNotNull($mtime);
            $this->assertFalse(rf_ini_should_reparse($tmp, $mtime));
        } finally {
            @unlink($tmp);
        }
    }

    public function testIniShouldReparse_trueAfterModification(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'rf-ini-test-');
        file_put_contents($tmp, "key = \"value\"\n");
        try {
            $first = rf_ini_current_mtime($tmp);
            // Force a different mtime — touch with a future timestamp.
            touch($tmp, $first + 60);
            clearstatcache();
            $this->assertTrue(rf_ini_should_reparse($tmp, $first));
        } finally {
            @unlink($tmp);
        }
    }

    public function testIniShouldReparse_seesExternalChangeDespiteStatCache(): void {
        // Regression: the listener stats the same INI path every tick and,
        // while auto-sync is off, stats nothing else. PHP's stat cache is a
        // single slot keyed on the last stat'd path, so without an explicit
        // clearstatcache() the first mtime is returned forever and a setting
        // written by the FPP UI (a different process) is never picked up.
        $tmp = tempnam(sys_get_temp_dir(), 'rf-ini-test-');
        file_put_contents($tmp, "key = \"value\"\n");
        try {
            // Launch the external modifier BEFORE priming the cache:
            // exec() itself clears PHP's stat cache, and in-process
            // touch()/file_put_contents() invalidate the entry too —
            // either would mask the bug. sleep() clears nothing.
            exec('(sleep 1; touch -t 203001010000 ' . escapeshellarg($tmp) . ') > /dev/null 2>&1 &');
            $first = rf_ini_current_mtime($tmp);
            $this->assertNotNull($first);
            // Prime the stat cache exactly like the listener loop does.
            filemtime($tmp);
            sleep(2);
            $this->assertTrue(rf_ini_should_reparse($tmp, $first));
            $this->assertNotSame($first, rf_ini_current_mtime($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    public function testIniShouldReparse_hotFileKeepsReparsingWithinGranularityWindow(): void {
        // Regression: filemtime is second-granular. A write landing in the
        // same second as the previous parse leaves the mtime "unchanged",
        // which (combined with WriteSettingToFile's skip-if-same-value
        // idempotence) sustained an infinite soft-restart loop. While the
        // last parse is within the granularity window, keep re-parsing.
        $tmp = tempnam(sys_get_temp_dir(), 'rf-ini-test-');
        file_put_contents($tmp, "key = \"value\"\n");
        try {
            $mtime = rf_ini_current_mtime($tmp);
            $this->assertNotNull($mtime);
            // Same second as the parse: hot, must re-parse despite equal mtime.
            $this->assertTrue(rf_ini_should_reparse($tmp, $mtime, $mtime));
            // One second later: still within the granularity window.
            $this->assertTrue(rf_ini_should_reparse($tmp, $mtime, $mtime + 1));
            // Two seconds later: window passed, equal mtime means unchanged.
            $this->assertFalse(rf_ini_should_reparse($tmp, $mtime, $mtime + 2));
            // No clock supplied (legacy callers): behavior unchanged.
            $this->assertFalse(rf_ini_should_reparse($tmp, $mtime));
        } finally {
            @unlink($tmp);
        }
    }

    public function testIniShouldReparse_trueWhenFileMissing(): void {
        $tmp = sys_get_temp_dir() . '/rf-ini-nonexistent-' . uniqid() . '.ini';
        // Defensive: stat fails → we say re-parse, so the listener's
        // existing error-path can surface the real problem.
        $this->assertTrue(rf_ini_should_reparse($tmp, 100));
        $this->assertTrue(rf_ini_should_reparse($tmp, null));
    }

    public function testIniCurrentMtime_returnsNullForMissingFile(): void {
        $tmp = sys_get_temp_dir() . '/rf-ini-nonexistent-' . uniqid() . '.ini';
        $this->assertNull(rf_ini_current_mtime($tmp));
    }

    public function testIniCurrentMtime_returnsIntForExistingFile(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'rf-ini-test-');
        file_put_contents($tmp, "x");
        try {
            $m = rf_ini_current_mtime($tmp);
            $this->assertIsInt($m);
            $this->assertGreaterThan(0, $m);
        } finally {
            @unlink($tmp);
        }
    }
}
