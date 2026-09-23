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
     * A name alone can't say which occurrence of a repeated sequence is
     * playing, so the name scan settles on the first. This is only the
     * fallback: rf_decide_next_scheduled_update resolves the real position
     * from FPP's current_playlist.index first (see the position tests below).
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

    // -------- rf_next_sequence_after --------

    public function testNextSequenceAfter_returnsFollowingEntry(): void {
        $playlist = [$this->seq('a.fseq'), $this->seq('b.fseq'), $this->seq('c.fseq')];
        $this->assertSame('b', rf_next_sequence_after($playlist, 0));
        $this->assertSame('c', rf_next_sequence_after($playlist, 1));
    }

    public function testNextSequenceAfter_wrapsAndSkipsEntriesWithoutSequenceName(): void {
        $playlist = [$this->seq('a.fseq'), new stdClass(), $this->seq('b.fseq'), new stdClass()];
        $this->assertSame('b', rf_next_sequence_after($playlist, 0));
        $this->assertSame('a', rf_next_sequence_after($playlist, 2));
    }

    public function testNextSequenceAfter_returnsEmptyWhenNoSequencesAtAll(): void {
        $this->assertSame('', rf_next_sequence_after([new stdClass(), new stdClass()], 0));
        $this->assertSame('', rf_next_sequence_after([], 0));
    }

    // -------- rf_main_playlist_position --------

    /**
     * Playlist JSON as returned by FPP's /api/playlist/{name}. $mainPlaylist
     * items are sequence filenames, or null for a pause.
     */
    private function fppPlaylist(array $mainPlaylist, array $leadIn = [], array $leadOut = []): stdClass {
        $toEntries = function (array $names): array {
            return array_map(function ($name) {
                return $name === null
                    ? (object) ['type' => 'pause', 'duration' => 5]
                    : (object) ['type' => 'sequence', 'sequenceName' => $name];
            }, $names);
        };
        return (object) [
            'leadIn' => $toEntries($leadIn),
            'mainPlaylist' => $toEntries($mainPlaylist),
            'leadOut' => $toEntries($leadOut),
        ];
    }

    /**
     * current_playlist as FPP's /api/system/status reports it: string values,
     * index 1-based across leadIn + mainPlaylist + leadOut (Playlist::GetPosition).
     */
    private function currentPlaylist($index, $count, string $name = 'MyShow'): stdClass {
        return (object) ['index' => (string) $index, 'count' => (string) $count, 'playlist' => $name];
    }

    public function testMainPlaylistPosition_indexIsOneBased(): void {
        $details = $this->fppPlaylist(['a.fseq', 'b.fseq', 'c.fseq']);
        $this->assertSame(0, rf_main_playlist_position($details, $this->currentPlaylist(1, 3), 'a'));
        $this->assertSame(2, rf_main_playlist_position($details, $this->currentPlaylist(3, 3), 'c'));
    }

    public function testMainPlaylistPosition_distinguishesRepeatedSequence(): void {
        $details = $this->fppPlaylist(['a.fseq', 'x.fseq', 'b.fseq', 'x.fseq', 'c.fseq']);
        $this->assertSame(1, rf_main_playlist_position($details, $this->currentPlaylist(2, 5), 'x'));
        $this->assertSame(3, rf_main_playlist_position($details, $this->currentPlaylist(4, 5), 'x'));
    }

    public function testMainPlaylistPosition_subtractsLeadIn(): void {
        $details = $this->fppPlaylist(['a.fseq', 'b.fseq'], ['intro.fseq', 'welcome.fseq']);
        $this->assertSame(0, rf_main_playlist_position($details, $this->currentPlaylist(3, 4), 'a'));
        $this->assertSame(1, rf_main_playlist_position($details, $this->currentPlaylist(4, 4), 'b'));
    }

    public function testMainPlaylistPosition_nullWhileInLeadInOrLeadOut(): void {
        $details = $this->fppPlaylist(['a.fseq'], ['intro.fseq'], ['outro.fseq']);
        $this->assertNull(rf_main_playlist_position($details, $this->currentPlaylist(1, 3), 'intro'));
        $this->assertNull(rf_main_playlist_position($details, $this->currentPlaylist(3, 3), 'outro'));
    }

    public function testMainPlaylistPosition_nullWhenIdleOrMissing(): void {
        $details = $this->fppPlaylist(['a.fseq']);
        // FPP reports index "0" / count "0" when idle.
        $this->assertNull(rf_main_playlist_position($details, $this->currentPlaylist(0, 0), 'a'));
        $this->assertNull(rf_main_playlist_position($details, null, 'a'));
        $this->assertNull(rf_main_playlist_position($details, (object) ['playlist' => 'MyShow'], 'a'));
    }

    public function testMainPlaylistPosition_nullOnNonNumericIndex(): void {
        $details = $this->fppPlaylist(['a.fseq']);
        $this->assertNull(rf_main_playlist_position($details, $this->currentPlaylist('abc', 1), 'a'));
        $this->assertNull(rf_main_playlist_position($details, $this->currentPlaylist('1.5', 1), 'a'));
    }

    public function testMainPlaylistPosition_nullWhenIndexPastEnd(): void {
        $details = $this->fppPlaylist(['a.fseq']);
        $this->assertNull(rf_main_playlist_position($details, $this->currentPlaylist(2, 1), 'a'));
    }

    /**
     * fppd loads a trimmed playlist for scheduled start/end ranges, drops
     * entries that fail to load, and a 60s-cached copy can predate an edit.
     * In each case its count stops matching the JSON, so the index would
     * point at the wrong entry.
     */
    public function testMainPlaylistPosition_nullWhenCountDisagreesWithJson(): void {
        $details = $this->fppPlaylist(['a.fseq', 'x.fseq', 'b.fseq', 'x.fseq']);
        $this->assertNull(rf_main_playlist_position($details, $this->currentPlaylist(2, 3), 'x'));
    }

    /**
     * fppd shuffles mainPlaylist in memory, so its index follows the shuffled
     * order, not the JSON order.
     */
    public function testMainPlaylistPosition_nullForRandomPlaylists(): void {
        foreach ([1, 2, '1', true] as $random) {
            $details = $this->fppPlaylist(['a.fseq', 'b.fseq']);
            $details->random = $random;
            $this->assertNull(rf_main_playlist_position($details, $this->currentPlaylist(1, 2), 'a'), var_export($random, true));
        }
        foreach ([0, '0', false] as $random) {
            $details = $this->fppPlaylist(['a.fseq', 'b.fseq']);
            $details->random = $random;
            $this->assertSame(0, rf_main_playlist_position($details, $this->currentPlaylist(1, 2), 'a'), var_export($random, true));
        }
    }

    /**
     * fppd flattens sub-playlists into its index. A JSON copy that still holds
     * a "playlist" entry (FPP ignored ?mergeSubs=1) is offset from it.
     */
    public function testMainPlaylistPosition_nullWhenJsonHasUnmergedSubPlaylist(): void {
        $details = $this->fppPlaylist(['a.fseq', 'x.fseq']);
        $details->mainPlaylist[0] = (object) ['type' => 'playlist', 'name' => 'PSA'];
        $this->assertNull(rf_main_playlist_position($details, $this->currentPlaylist(2, 2), 'x'));
    }

    public function testMainPlaylistPosition_nullWhenEntryAtIndexIsNotWhatIsPlaying(): void {
        $details = $this->fppPlaylist(['a.fseq', 'b.fseq']);
        $this->assertNull(rf_main_playlist_position($details, $this->currentPlaylist(1, 2), 'b'));
    }

    public function testMainPlaylistPosition_nullWhenEntryAtIndexHasNoSequence(): void {
        $details = $this->fppPlaylist(['a.fseq', null]);
        $this->assertNull(rf_main_playlist_position($details, $this->currentPlaylist(2, 2), ''));
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

    /**
     * Reported bug: once playback reached a later occurrence of a repeated
     * sequence, "next scheduled" showed what follows the FIRST occurrence.
     */
    public function testDecideNextScheduledUpdate_usesFppIndexForRepeatedSequence(): void {
        $details = $this->fppPlaylist(['a.fseq', 'x.fseq', 'b.fseq', 'x.fseq', 'c.fseq']);
        $this->assertSame('c', rf_decide_next_scheduled_update($details, 'MyShow', 'x', '', 'Other', $this->currentPlaylist(4, 5)));
    }

    /**
     * Walks every position of a playlist that mixes a lead-in, pauses and
     * repeats (the same sequence back to back, and again at the wrap point)
     * and checks each one against the answer read straight off the list.
     * A positional bug anywhere in the mapping fails at least one step.
     */
    public function testDecideNextScheduledUpdate_everyPositionOfPlaylistWithRepeats(): void {
        $leadIn = ['intro.fseq'];
        $main = ['psa.fseq', 'a.fseq', 'psa.fseq', null, 'b.fseq', 'psa.fseq', 'psa.fseq', 'c.fseq', 'psa.fseq'];
        $details = $this->fppPlaylist($main, $leadIn);
        $count = count($leadIn) + count($main);

        // What should follow each mainPlaylist position (pause skipped, wraps).
        $expected = ['a', 'psa', 'b', null, 'psa', 'psa', 'c', 'psa', 'psa'];

        foreach ($main as $pos => $name) {
            if ($name === null) {
                continue;  // FPP reports no sequence while a pause plays
            }
            $playing = pathinfo($name, PATHINFO_FILENAME);
            $status = $this->currentPlaylist(count($leadIn) + $pos + 1, $count);
            $this->assertSame(
                $expected[$pos],
                rf_decide_next_scheduled_update($details, 'MyShow', $playing, '', 'Other', $status),
                "mainPlaylist position $pos ($playing)"
            );
        }
    }

    public function testDecideNextScheduledUpdate_fallsBackToNameScanWhenIndexUnusable(): void {
        $details = $this->fppPlaylist(['a.fseq', 'x.fseq', 'b.fseq', 'x.fseq', 'c.fseq']);
        $details->random = 1;
        // Index can't be trusted for a random playlist; name scan still
        // produces an answer rather than nothing.
        $this->assertSame('b', rf_decide_next_scheduled_update($details, 'MyShow', 'x', '', 'Other', $this->currentPlaylist(4, 5)));
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

    // -------- rf_fpp_failure_response (false "FPPD is not running") --------

    public function testFppFailure_singleMissIsNotTreatedAsDown(): void {
        // The whole point: one failed probe is a slow localhost round-trip,
        // not an outage. Nothing is logged and nothing is declared down.
        $r = rf_fpp_failure_response(1, 1.0);
        $this->assertFalse($r['confirmedDown']);
        $this->assertFalse($r['logDown']);
    }

    public function testFppFailure_transientKeepsNormalCadence(): void {
        // Regression guard for the reported bug: the old code slept 5s on
        // any miss, which is longer than the default 3s request-fetch
        // window, so one false alarm skipped a queue check entirely.
        // A transient miss must not stretch the poll interval.
        foreach ([1, 2] as $failures) {
            $r = rf_fpp_failure_response($failures, 1.0);
            $this->assertSame(1.0, $r['sleepSeconds'], "failure $failures should hold cadence");
            $this->assertLessThan(3.0, $r['sleepSeconds'], 'must stay inside the request-fetch window');
        }
    }

    public function testFppFailure_declaresDownAtThreshold(): void {
        $r = rf_fpp_failure_response(RF_FPP_DOWN_THRESHOLD, 1.0);
        $this->assertTrue($r['confirmedDown']);
        $this->assertTrue($r['logDown']);
        $this->assertSame(5.0, $r['sleepSeconds']);
    }

    public function testFppFailure_logsOnceNotEveryPollWhileDown(): void {
        // Sustained outage should produce one line, not one per poll.
        $this->assertTrue(rf_fpp_failure_response(RF_FPP_DOWN_THRESHOLD, 1.0)['logDown']);
        for ($n = RF_FPP_DOWN_THRESHOLD + 1; $n <= RF_FPP_DOWN_THRESHOLD + 5; $n++) {
            $r = rf_fpp_failure_response($n, 1.0);
            $this->assertTrue($r['confirmedDown']);
            $this->assertFalse($r['logDown'], "failure $n should not re-log");
        }
    }

    public function testFppFailure_downBackoffNeverFasterThanConfigured(): void {
        // Mirrors rf_next_poll_seconds: backoff must not poll harder than asked.
        $this->assertSame(10.0, rf_fpp_failure_response(RF_FPP_DOWN_THRESHOLD, 10.0)['sleepSeconds']);
    }

    public function testFppFailure_respectsCustomThreshold(): void {
        $this->assertFalse(rf_fpp_failure_response(1, 1.0, 2)['confirmedDown']);
        $this->assertTrue(rf_fpp_failure_response(2, 1.0, 2)['confirmedDown']);
        $this->assertTrue(rf_fpp_failure_response(2, 1.0, 2)['logDown']);
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
