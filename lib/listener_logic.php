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
     * A repeated sequence matches its FIRST occurrence, so this is only the
     * fallback for when rf_main_playlist_position can't pin down the entry.
     *
     * @param array $mainPlaylist Array of stdClass items each with a
     *                            ->sequenceName property.
     * @param string $currentlyPlaying Base filename to look for.
     * @return string Base filename of the next sequence, or "" if not found.
     */
    function rf_get_next_sequence(array $mainPlaylist, string $currentlyPlaying): string {
        $count = count($mainPlaylist);
        for ($i = 0; $i < $count; $i++) {
            if (!isset($mainPlaylist[$i]->sequenceName)) {
                continue;
            }
            if (pathinfo($mainPlaylist[$i]->sequenceName, PATHINFO_FILENAME) !== $currentlyPlaying) {
                continue;
            }
            return rf_next_sequence_after($mainPlaylist, $i);
        }
        return "";
    }

    /**
     * The sequence that follows $mainPlaylist[$position]. Scans forward
     * (wrapping) past entries with no sequenceName (pauses, etc.) so
     * NEXT_PLAYLIST stays populated even when a pause immediately follows
     * the current sequence.
     *
     * @return string Base filename of the next sequence, or "" if none.
     */
    function rf_next_sequence_after(array $mainPlaylist, int $position): string {
        $count = count($mainPlaylist);
        for ($step = 1; $step <= $count; $step++) {
            $j = ($position + $step) % $count;
            if (isset($mainPlaylist[$j]->sequenceName)) {
                return pathinfo($mainPlaylist[$j]->sequenceName, PATHINFO_FILENAME);
            }
        }
        return "";
    }

    /**
     * Resolve which mainPlaylist entry is playing from FPP's status, so a
     * sequence that appears more than once maps to the right occurrence.
     *
     * FPP's current_playlist.index is 1-based and counts leadIn, mainPlaylist
     * and leadOut as one list (Playlist::GetPosition in fppd). It describes
     * fppd's in-memory playlist, which can differ from the JSON copy, so the
     * index is only trusted when:
     *  - current_playlist.count equals the JSON's total entries (fppd trims
     *    scheduled start/end ranges and drops entries that fail to load, and
     *    the listener's cached JSON can predate an edit);
     *  - the playlist isn't random (fppd shuffles mainPlaylist in memory);
     *  - no "playlist" entries remain (fppd flattens sub-playlists into its
     *    index; fetch the JSON with ?mergeSubs=1 to match);
     *  - the entry at that position is the sequence FPP says is playing.
     *
     * @param stdClass  $playlistDetails       Playlist JSON from /api/playlist.
     * @param ?stdClass $currentPlaylistStatus current_playlist from /api/system/status.
     * @param string    $currentlyPlaying      Base filename FPP reports playing.
     * @return ?int 0-based mainPlaylist position, or null if it can't be trusted.
     */
    function rf_main_playlist_position(stdClass $playlistDetails, ?stdClass $currentPlaylistStatus, string $currentlyPlaying): ?int {
        if ($currentPlaylistStatus === null
            || !isset($currentPlaylistStatus->index, $currentPlaylistStatus->count)) {
            return null;
        }
        $index = filter_var($currentPlaylistStatus->index, FILTER_VALIDATE_INT);
        $count = filter_var($currentPlaylistStatus->count, FILTER_VALIDATE_INT);
        if ($index === false || $count === false || $index < 1) {
            return null;
        }
        if (!empty($playlistDetails->random)) {
            return null;
        }

        $sections = [];
        foreach (['leadIn', 'mainPlaylist', 'leadOut'] as $name) {
            $section = $playlistDetails->$name ?? [];
            $sections[$name] = is_array($section) ? $section : [];
        }
        $total = 0;
        foreach ($sections as $section) {
            foreach ($section as $entry) {
                if (isset($entry->type) && $entry->type === 'playlist') {
                    return null;
                }
            }
            $total += count($section);
        }
        if ($count !== $total) {
            return null;
        }

        $position = $index - 1 - count($sections['leadIn']);
        $mainPlaylist = $sections['mainPlaylist'];
        if ($position < 0 || $position >= count($mainPlaylist)) {
            return null;
        }
        if (!isset($mainPlaylist[$position]->sequenceName)
            || pathinfo($mainPlaylist[$position]->sequenceName, PATHINFO_FILENAME) !== $currentlyPlaying) {
            return null;
        }
        return $position;
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
     * Whether a Show Token has been entered. The listener makes no request
     * to the Plugins API until this is true, which is what lets the FPP 10
     * privacy disclosure declare every send as alwaysOn: false (#194).
     * Same threshold as the commands/_lib.php callers (strlen <= 1 = unset).
     *
     * @param mixed $token Raw remoteToken setting (already urldecoded).
     */
    function rf_has_token($token): bool {
        return strlen(trim((string) $token)) > 1;
    }

    /**
     * Seconds between attempts to read the viewer control mode while it is
     * still unknown (startup fetch failed, e.g. FPP booted before Wi-Fi).
     */
    if (!defined('RF_MODE_RETRY_SECONDS')) {
        define('RF_MODE_RETRY_SECONDS', 30);
    }

    /**
     * Minimum seconds between re-reads of a known mode. Re-reads are only
     * triggered by an empty request/vote fetch (see rf_should_refresh_mode).
     */
    if (!defined('RF_MODE_REFRESH_SECONDS')) {
        define('RF_MODE_REFRESH_SECONDS', 300);
    }

    /**
     * Extract the viewer control mode from a /remotePreferences response.
     * Returns null for a failed fetch or an unrecognised value, so callers
     * can tell "unknown" apart from a real mode.
     *
     * @param mixed $prefs Decoded response (stdClass) or null.
     * @return string|null "voting", "jukebox" or null.
     */
    function rf_parse_viewer_control_mode($prefs): ?string {
        if (!($prefs instanceof stdClass) || !isset($prefs->viewerControlMode)) {
            return null;
        }
        $mode = strtolower(trim((string) $prefs->viewerControlMode));
        return in_array($mode, ['voting', 'jukebox'], true) ? $mode : null;
    }

    /**
     * Whether the listener should (re)read the viewer control mode now.
     *
     * The mode used to be read once at startup and cached forever, so a
     * failed boot-time fetch or a mode change in the control panel left the
     * plugin asking the wrong endpoint all night (votes or requests never
     * consumed). Now:
     *  - unknown mode: retry every $retrySeconds until it can be read;
     *  - known mode: re-read only after a fetch came back empty, at most
     *    every $refreshSeconds. A busy, working show never pays for it, and
     *    a mismatched plugin always gets empty answers, so it self-corrects.
     *
     * @param bool $confirmed           Mode has been read successfully.
     * @param bool $emptyFetchSinceLast A request/vote fetch came back empty
     *                                  since the last mode read.
     * @param int  $lastAttemptAt       Unix time of the last read attempt.
     */
    function rf_should_refresh_mode(
        bool $confirmed,
        bool $emptyFetchSinceLast,
        int $now,
        int $lastAttemptAt,
        int $retrySeconds = RF_MODE_RETRY_SECONDS,
        int $refreshSeconds = RF_MODE_REFRESH_SECONDS
    ): bool {
        if (!$confirmed) {
            return ($now - $lastAttemptAt) >= $retrySeconds;
        }
        return $emptyFetchSinceLast && ($now - $lastAttemptAt) >= $refreshSeconds;
    }

    /**
     * Body for the /fppHeartbeat POST. Lets Remote Falcon tell a plugin that
     * is connected but not taking requests or votes apart from a healthy one.
     *
     * @param int|null $lastControlFetchAt Unix time of the last request/vote
     *                                     fetch, or null if none yet.
     */
    function rf_heartbeat_payload(string $pluginVersion, string $mode, bool $modeConfirmed, ?int $lastControlFetchAt): array {
        return [
            'pluginVersion' => $pluginVersion,
            'viewerControlMode' => $mode,
            'modeConfirmed' => $modeConfirmed,
            'lastControlFetchAt' => $lastControlFetchAt,
        ];
    }

    /**
     * Warning to log when the remote playlist can't work, or null when fine.
     * Callers only ask once FPP has answered (see checkRemotePlaylist).
     */
    function rf_remote_playlist_warning(string $remotePlaylist, bool $foundInFpp): ?string {
        if (trim($remotePlaylist) === '') {
            return "WARNING - No remote playlist is set. Viewer requests and votes can't play until you pick one on the plugin page and sync it.";
        }
        if (!$foundInFpp) {
            return "WARNING - Remote playlist '" . $remotePlaylist . "' was not found in FPP. Viewer requests and votes can't play until it exists and is synced.";
        }
        return null;
    }

    /**
     * Whether now is a safe moment for a blocking call (mode re-check,
     * remote playlist check). Those can take seconds, so never run them close
     * to the end of a song, where the request/vote fetch has to happen.
     */
    function rf_is_quiet_moment(stdClass $fppStatus, int $minSecondsRemaining = 10): bool {
        if (($fppStatus->status_name ?? '') === 'idle') {
            return true;
        }
        return isset($fppStatus->seconds_remaining) && (int) $fppStatus->seconds_remaining >= $minSecondsRemaining;
    }

    /**
     * Whether to (re)check that the remote playlist exists in FPP. Stops once
     * it has been found; a missing playlist or an FPP that didn't answer is
     * retried every $retrySeconds, so a fix or a still-loading fppd is picked
     * up without a listener restart.
     *
     * @param int $lastCheckAt Unix time of the last check, 0 if never.
     */
    function rf_should_check_remote_playlist(bool $found, int $lastCheckAt, int $now, stdClass $fppStatus, int $retrySeconds = 60): bool {
        if ($found) {
            return false;
        }
        if ($lastCheckAt > 0 && ($now - $lastCheckAt) < $retrySeconds) {
            return false;
        }
        return rf_is_quiet_moment($fppStatus);
    }

    /**
     * Whether a request/vote fetch result is worth rewriting the status file
     * for. Interrupt mode polls an empty queue about once a second, so a run
     * of identical empty results is written at most every $minSeconds; any
     * change, and every real song, is written straight away.
     *
     * @param array|null $prev Last written ['at','kind','sequence','error'].
     */
    function rf_should_write_fetch_status(?array $prev, array $next, int $minSeconds = 60): bool {
        if ($prev === null || $next['sequence'] !== null) {
            return true;
        }
        foreach (['kind', 'sequence', 'error'] as $key) {
            if (($prev[$key] ?? null) !== ($next[$key] ?? null)) {
                return true;
            }
        }
        return ($next['at'] - ($prev['at'] ?? 0)) >= $minSeconds;
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
     * The current entry is located by FPP's playlist index when that can be
     * trusted (see rf_main_playlist_position), else by name.
     *
     * The caller is responsible for making the HTTP fetch of $playlistDetails
     * and for updating any cached state after a successful post.
     */
    function rf_decide_next_scheduled_update(
        ?stdClass $playlistDetails,
        string $currentPlaylist,
        string $currentlyPlaying,
        string $nextScheduledInRF,
        string $remotePlaylist,
        ?stdClass $currentPlaylistStatus = null
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

        $position = rf_main_playlist_position($playlistDetails, $currentPlaylistStatus, $currentlyPlaying);
        $nextScheduled = $position !== null
            ? rf_next_sequence_after($mainPlaylist, $position)
            : rf_get_next_sequence($mainPlaylist, $currentlyPlaying);

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
     * How many consecutive failed status probes before the listener is
     * willing to say fppd is down. One failed probe is a blip, not an
     * outage.
     */
    if (!defined('RF_FPP_DOWN_THRESHOLD')) {
        define('RF_FPP_DOWN_THRESHOLD', 3);
    }

    /**
     * Decide what to do after an FPP status probe came back empty.
     *
     * Previously any single failure logged "FPPD is not running!" and slept
     * 5s. That 5s is longer than the whole request-fetch window (default
     * requestFetchTime is 3s), so one false alarm deterministically skipped
     * the queue check for that sequence and a viewer's request landed a
     * sequence late. Here a transient miss keeps the normal cadence so the
     * fetch window survives, and only a sustained run of failures backs off
     * and reports fppd as down.
     *
     * 'logDown' fires only on the transition into the down state so a truly
     * dead fppd produces one line rather than one per poll.
     *
     * @return array{confirmedDown: bool, sleepSeconds: float, logDown: bool}
     */
    function rf_fpp_failure_response(int $consecutiveFailures, float $configuredSeconds, int $downThreshold = RF_FPP_DOWN_THRESHOLD): array {
        $confirmedDown = $consecutiveFailures >= $downThreshold;
        return [
            'confirmedDown' => $confirmedDown,
            // Keep the normal cadence while it's still just a blip; back off
            // to 5s only once it looks like a real outage.
            'sleepSeconds'  => $confirmedDown ? max(5.0, $configuredSeconds) : $configuredSeconds,
            'logDown'       => $consecutiveFailures === $downThreshold,
        ];
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
     *
     * clearstatcache is required: the writes come from OTHER processes
     * (FPP's apache), and PHP's single-slot stat cache otherwise returns
     * the first stat result forever when this is the only path the loop
     * stats — the daemon would never see a settings change.
     */
    function rf_ini_should_reparse(string $path, ?int $lastMtime, ?int $nowTs = null): bool {
        clearstatcache(false, $path);
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return true;
        }
        if ($lastMtime === null) {
            return true;
        }
        if ($mtime !== $lastMtime) {
            return true;
        }
        // filemtime is second-granular. If the last parse happened within the
        // current (or previous) second, a writer can still land in that same
        // second WITHOUT changing the mtime we compare against — so treat the
        // file as "hot" and keep re-parsing until the granularity window has
        // safely passed. Costs a couple of extra parses right after a write.
        if ($nowTs !== null && $nowTs <= $lastMtime + 1) {
            return true;
        }
        return false;
    }

    /**
     * Return the current mtime of the file, or null if it can't be stat'd.
     * Caller stores this and passes it back to rf_ini_should_reparse.
     */
    function rf_ini_current_mtime(string $path): ?int {
        clearstatcache(false, $path);
        $mtime = @filemtime($path);
        return $mtime === false ? null : $mtime;
    }

    /**
     * Auto Sync Playlist decision (issue #13): should the listener re-sync
     * the remote playlist to RF on this tick?
     *
     * A sync fires only after the playlist file's mtime has changed AND then
     * held unchanged for the full $quietSeconds window. Every further change
     * resets the window, so an active editing session in the FPP UI
     * coalesces into a single sync shortly after the last save — the
     * listener never syncs mid-edit.
     *
     * State machine over ($prevMtime, $changedAt):
     *   - file missing            -> clear state, never sync
     *   - first observation       -> baseline (no sync on listener start)
     *   - mtime changed           -> remember it, (re)start the quiet window
     *   - stable & window elapsed -> SYNC, close the window
     *   - otherwise               -> wait
     *
     * @param ?int      $prevMtime    Last observed mtime (null = none yet).
     * @param ?int      $changedAt    Timestamp when the pending change was
     *                                first observed (null = no pending change).
     * @param int|false|null $currMtime Current filemtime() result.
     * @param int       $now          Current unix timestamp.
     * @param int       $quietSeconds Quiet window; production uses 30.
     * @return array ['sync' => bool, 'lastMtime' => ?int, 'changedAt' => ?int]
     */
    function rf_auto_sync_decision(?int $prevMtime, ?int $changedAt, $currMtime, int $now, int $quietSeconds = 30): array {
        if ($currMtime === false || $currMtime === null) {
            return ['sync' => false, 'lastMtime' => null, 'changedAt' => null];
        }
        if ($prevMtime === null) {
            return ['sync' => false, 'lastMtime' => $currMtime, 'changedAt' => null];
        }
        if ($currMtime !== $prevMtime) {
            return ['sync' => false, 'lastMtime' => $currMtime, 'changedAt' => $now];
        }
        if ($changedAt !== null && ($now - $changedAt) >= $quietSeconds) {
            return ['sync' => true, 'lastMtime' => $currMtime, 'changedAt' => null];
        }
        return ['sync' => false, 'lastMtime' => $currMtime, 'changedAt' => $changedAt];
    }
}
