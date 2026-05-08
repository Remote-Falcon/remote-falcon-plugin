<?php
// Pure-logic helpers extracted from remote_falcon_listener.php so they
// can be unit-tested independently of FPP.
//
// Functions in this file MUST NOT have side effects beyond their return
// value (no file I/O, no HTTP, no global mutation, no logging).
// Anything in here should be testable in isolation with PHPUnit.

if (!function_exists('rf_get_next_sequence')) {

    /**
     * Find the sequence that comes after $currentlyPlaying in $mainPlaylist.
     * Wraps to the first sequence if $currentlyPlaying is the last entry.
     * Matches by base filename (no path, no extension).
     *
     * @param array $mainPlaylist Array of stdClass items each with a
     *                            ->sequenceName property.
     * @param string $currentlyPlaying Base filename to look for.
     * @return string Base filename of the next sequence, or "" if not found.
     */
    function rf_get_next_sequence(array $mainPlaylist, string $currentlyPlaying): string {
        $nextScheduled = "";
        $count = count($mainPlaylist);
        for ($i = 0; $i < $count; $i++) {
            if (!isset($mainPlaylist[$i]->sequenceName)) {
                continue;
            }
            if (pathinfo($mainPlaylist[$i]->sequenceName, PATHINFO_FILENAME) !== $currentlyPlaying) {
                continue;
            }
            if ($i + 1 === $count) {
                $nextScheduled = $mainPlaylist[0]->sequenceName ?? "";
            } else {
                $nextScheduled = $mainPlaylist[$i + 1]->sequenceName ?? "";
            }
            break;
        }
        return pathinfo($nextScheduled, PATHINFO_FILENAME);
    }

    /**
     * Clamp the FPP status check interval to a safe minimum. Values <= 0
     * would cause usleep(0) busy-loops; values < 0.1 risk starving other
     * system work on resource-constrained hosts.
     *
     * @param mixed $value Value as read from the INI file.
     * @return float Clamped value, always >= 0.1.
     */
    function rf_clamp_status_check_time($value): float {
        $f = floatval($value);
        if ($f < 0.1) {
            return 0.1;
        }
        return $f;
    }

    /**
     * Decide whether to push an "updateWhatsPlaying" to RF.
     * Returns the value to post (echoes $currentlyPlaying) when the listener's
     * cached "what RF thinks is playing" disagrees with what FPP actually plays.
     * Returns null when no update is needed.
     */
    function rf_decide_currently_playing_update(string $currentlyPlaying, string $currentlyPlayingInRF): ?string {
        return $currentlyPlaying !== $currentlyPlayingInRF ? $currentlyPlaying : null;
    }

    /**
     * Decide what value to post as "next scheduled sequence" given current
     * state. Returns null if the listener should skip the update.
     *
     * Skips when:
     *  - playlist details aren't available (HTTP fetch failed).
     *  - the playlist has no mainPlaylist or it's empty / not an array.
     *  - the next-scheduled value matches what we last told RF (no change).
     *  - FPP is currently playing the user's Remote Falcon playlist (we don't
     *    track "next scheduled" while RF is in control of sequencing).
     *
     * The caller is responsible for making the HTTP fetch of $playlistDetails
     * and for updating any cached state after a successful post.
     */
    function rf_decide_next_scheduled_update(
        ?stdClass $playlistDetails,
        string $currentPlaylist,
        string $currentlyPlaying,
        string $nextScheduledInRF,
        string $remotePlaylist
    ): ?string {
        if ($playlistDetails === null) {
            return null;
        }
        if (!isset($playlistDetails->mainPlaylist)) {
            return null;
        }
        $mainPlaylist = $playlistDetails->mainPlaylist;
        if (!is_array($mainPlaylist) || count($mainPlaylist) === 0) {
            return null;
        }

        $nextScheduled = rf_get_next_sequence($mainPlaylist, $currentlyPlaying);

        if ($nextScheduled === $nextScheduledInRF) {
            return null;
        }
        if ($currentPlaylist === $remotePlaylist) {
            return null;
        }

        return $nextScheduled;
    }

    /**
     * Extract the base filename of what FPP is currently playing.
     * Falls back from current_sequence to current_song for media-only items.
     * Returns "" if neither is populated.
     */
    function rf_extract_currently_playing(stdClass $fppStatus): string {
        $name = "";
        if (isset($fppStatus->current_sequence)) {
            $name = pathinfo($fppStatus->current_sequence, PATHINFO_FILENAME);
        }
        if ($name === "" && isset($fppStatus->current_song)) {
            $name = pathinfo($fppStatus->current_song, PATHINFO_FILENAME);
        }
        return $name;
    }

    /**
     * In non-interrupt mode, decide whether to skip this iteration's RF
     * fetch because we already queued for the same sequence recently.
     *
     * NOTE: the current behavior keys dedup on the sequence NAME, which
     * means a playlist with two consecutive instances of the same sequence
     * (or the same sequence wrapping back-to-back at end of playlist) will
     * incorrectly suppress the second queue. The perf branch's correctness
     * fix changes the key to (playlist, position, start_time). This
     * function locks in current behavior so the bug is visible in tests.
     */
    function rf_should_skip_non_interrupt_check(
        string $currentlyPlaying,
        string $lastQueuedSequence,
        int $now,
        int $lastQueuedTime,
        int $requestFetchTime,
        int $additionalWaitTime
    ): bool {
        if ($currentlyPlaying !== $lastQueuedSequence) {
            return false;
        }
        $window = $requestFetchTime + $additionalWaitTime + 2;
        return ($now - $lastQueuedTime) < $window;
    }

    /**
     * In interrupt mode, decide whether to skip this iteration's interrupt
     * because we recently fired one. Unlike non-interrupt mode this does
     * NOT key on the sequence name; any recent interrupt blocks another.
     */
    function rf_should_skip_interrupt_check(
        int $now,
        int $lastQueuedTime,
        int $requestFetchTime,
        int $additionalWaitTime
    ): bool {
        $window = $requestFetchTime + $additionalWaitTime + 2;
        return ($now - $lastQueuedTime) < $window;
    }

    /**
     * Whether the current sequence is close enough to ending that the
     * non-interrupt loop should fetch the next request/vote.
     */
    function rf_should_fetch_now(int $secondsRemaining, int $requestFetchTime): bool {
        return $secondsRemaining < $requestFetchTime;
    }

    /**
     * The number of seconds the listener should sleep before its next FPP
     * status poll. When FPP is idle (between scheduled blocks, no playlist
     * running), back off to 5s — there's nothing to react to and the user
     * doesn't notice idle-state polling latency. When playing, use the
     * configured fppStatusCheckTime so reaction stays fast.
     */
    function rf_next_poll_seconds(string $statusName, float $configuredSeconds): float {
        if ($statusName === 'idle') {
            // At least 5s, but never faster than configured (idle is a
            // backoff — should never poll FPP harder than the user asked).
            return max(5.0, $configuredSeconds);
        }
        return $configuredSeconds;
    }

    /**
     * In-memory cache for FPP playlist details. The listener fetches the
     * playlist every poll (~1 Hz) but FPP playlists almost never change
     * mid-show; caching by name with a TTL of 60s typically removes
     * thousands of FPP HTTP calls per hour during a show.
     *
     * Cache lives in a static variable inside an internal helper. Tests
     * call rf_playlist_cache_clear() between scenarios.
     */
    function &_rf_playlist_cache_ref(): array {
        static $cache = [];
        return $cache;
    }

    function rf_playlist_cache_get(string $key, float $now, float $maxAgeSeconds): ?stdClass {
        $cache = &_rf_playlist_cache_ref();
        if (!isset($cache[$key])) {
            return null;
        }
        if (($now - $cache[$key]['at']) > $maxAgeSeconds) {
            return null;
        }
        return $cache[$key]['value'];
    }

    function rf_playlist_cache_put(string $key, stdClass $value, float $now): void {
        $cache = &_rf_playlist_cache_ref();
        $cache[$key] = ['at' => $now, 'value' => $value];
    }

    function rf_playlist_cache_clear(): void {
        $cache = &_rf_playlist_cache_ref();
        $cache = [];
    }

    /**
     * Check whether the listener's settings INI file needs to be re-parsed.
     * The listener loop re-parses every tick to pick up flag changes
     * (Stop / Restart). filemtime() is far cheaper than parse_ini_file()
     * — when the file hasn't changed since our last parse, we can reuse
     * the previously decoded array.
     *
     * Returns true when:
     *   - we have no prior mtime (first call)
     *   - filemtime() can't stat the file (defensive: re-parse to surface
     *     errors to the listener's existing error path)
     *   - the file has been modified since $lastMtime
     *
     * Note: WriteSettingToFile always rewrites the file, which bumps mtime.
     * So flag changes via the FPP UI / commands always trigger a re-parse.
     */
    function rf_ini_should_reparse(string $path, ?int $lastMtime): bool {
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return true;
        }
        if ($lastMtime === null) {
            return true;
        }
        return $mtime !== $lastMtime;
    }

    /**
     * Return the current mtime of the file, or null if it can't be stat'd.
     * Caller stores this and passes it back to rf_ini_should_reparse.
     */
    function rf_ini_current_mtime(string $path): ?int {
        $mtime = @filemtime($path);
        return $mtime === false ? null : $mtime;
    }
}
