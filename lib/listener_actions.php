<?php
// Action layer for the Remote Falcon listener. Each function here is one
// of the listener's "side-effecting" operations: HTTP wrappers around the
// FPP/RF APIs, the queue/state-update orchestrators, and the two big
// queueing functions (doNonInterruptStuff / doInterruptStuff).
//
// These functions read $GLOBALS for state (lastQueuedSequence, lastQueuedTime,
// pluginsApiPath, remotePlaylist, currentlyPlayingInRF, nextScheduledInRF)
// and for the FPP base URL (fppBaseUrl, defaulted by rf_fpp_base_url()).
// They depend on lib/listener_logic.php (pure decision helpers),
// lib/listener_http.php (transport), and lib/listener_log.php (log output).
//
// Tests can override the FPP base URL by setting $GLOBALS['fppBaseUrl']
// before calling any wrapper.

require_once __DIR__ . '/listener_logic.php';
require_once __DIR__ . '/listener_http.php';
require_once __DIR__ . '/listener_log.php';

if (!function_exists('rf_fpp_base_url')) {

    /**
     * The base URL the listener uses for FPP localhost API calls.
     * Production: defaults to http://127.0.0.1.
     * Tests: override by setting $GLOBALS['fppBaseUrl'] to a mock URL.
     */
    function rf_fpp_base_url(): string {
        return $GLOBALS['fppBaseUrl'] ?? 'http://127.0.0.1';
    }

    // -------- HTTP wrappers (FPP) --------

    /**
     * Fetch fppd's status, retrying briefly before giving up.
     *
     * A single probe failure is almost never fppd being down — far more
     * often it's a slow localhost round-trip (a reaped Apache worker paying
     * fork+init on a small controller). Two quick retries turn that into a
     * non-event instead of a scary log line and a skipped queue check.
     * The reason from the probe is logged so a real problem is still
     * diagnosable: "unreachable" is a genuine connection failure, while
     * http_error/bad_body mean fppd answered and is therefore running.
     */
    function getFppStatus() {
        $attempts = 3;
        $backoffMicros = [250000, 500000]; // 250ms, then 500ms
        $lastReason = 'unreachable';

        for ($i = 0; $i < $attempts; $i++) {
            $probe = rf_http_fpp_get_status_result(rf_fpp_base_url());
            if ($probe['ok']) {
                if ($i > 0) {
                    logEntry_verbose("FPP status probe succeeded on attempt " . ($i + 1));
                }
                return $probe['status'];
            }
            $lastReason = $probe['reason'];
            if ($probe['reason'] === 'http_error') {
                $lastReason .= ' (HTTP ' . $probe['httpStatus'] . ')';
            }
            if ($i < count($backoffMicros)) {
                usleep($backoffMicros[$i]);
            }
        }

        logEntry_verbose("WARNING - FPP status probe failed after " . $attempts . " attempts (" . $lastReason . ")");
        return null;
    }

    function getPlaylistDetails($remotePlaylistEncoded) {
        $result = rf_http_fpp_get_playlist(rf_fpp_base_url(), $remotePlaylistEncoded, 1, true);
        if ($result === null) {
            logEntry_verbose("ERROR - Failed to get playlist details for: " . rawurldecode($remotePlaylistEncoded));
        }
        return $result;
    }

    function insertPlaylistImmediate($remotePlaylistEncoded, $index) {
        $ok = rf_http_fpp_insert_immediate(rf_fpp_base_url(), $remotePlaylistEncoded, (int) $index);
        if (!$ok) {
            logEntry("ERROR - Failed to insert playlist immediate: " . rawurldecode($remotePlaylistEncoded) . " at index " . $index);
            return false;
        }
        logEntry_verbose("SUCCESS - Inserted playlist immediate");
        return true;
    }

    function insertPlaylistAfterCurrent($remotePlaylistEncoded, $index) {
        $ok = rf_http_fpp_insert_after_current(rf_fpp_base_url(), $remotePlaylistEncoded, (int) $index);
        if (!$ok) {
            logEntry("ERROR - Failed to insert playlist after current: " . rawurldecode($remotePlaylistEncoded) . " at index " . $index);
            return false;
        }
        logEntry_verbose("SUCCESS - Inserted playlist after current");
        return true;
    }

    // -------- HTTP wrappers (Remote Falcon plugins API) --------

    // V17 heartbeat — fire-and-forget liveness POST to the RF plugins API.
    // Failures are logged at verbose level only; a missed heartbeat must never
    // disrupt the show loop. The body carries the listener's mode and when it
    // last asked for a request/vote, so RF can spot "connected but not taking
    // votes" (PRD-026).
    function fppHeartbeat($remoteToken) {
        $payload = rf_heartbeat_payload(
            (string) ($GLOBALS['PLUGIN_VERSION'] ?? ''),
            (string) ($GLOBALS['viewerControlMode'] ?? ''),
            !empty($GLOBALS['modeConfirmed']),
            isset($GLOBALS['lastControlFetchAt']) ? (int) $GLOBALS['lastControlFetchAt'] : null
        );
        $ok = rf_http_rf_heartbeat($GLOBALS['pluginsApiPath'], $remoteToken, 5, $payload);
        if (!$ok) {
            logEntry_verbose("WARNING - Heartbeat post failed to: " . $GLOBALS['pluginsApiPath'] . "/fppHeartbeat");
        }
        rf_status_update(['lastHeartbeat' => ['at' => time(), 'ok' => $ok]]);
        return $ok;
    }

    function updateWhatsPlaying($currentlyPlaying, $remoteToken) {
        $start_time = microtime(true);
        logEntry_verbose("Calling Plugins API to update what's playing");
        $ok = rf_http_rf_update_whats_playing($GLOBALS['pluginsApiPath'], $remoteToken, $currentlyPlaying);
        if (!$ok) {
            logEntry("ERROR - Failed to update what's playing to: " . $GLOBALS['pluginsApiPath'] . "/updateWhatsPlaying");
            return false;
        }
        logEntry_verbose("SUCCESS - Calling Plugins API to update what's playing. Execution time: " . ((microtime(true) - $start_time) * 1000) . " ms");
        return true;
    }

    function updateNextScheduledSequenceInRf($nextScheduled, $remoteToken) {
        $start_time = microtime(true);
        logEntry_verbose("Calling Plugins API to update next scheduled");
        $ok = rf_http_rf_update_next_scheduled($GLOBALS['pluginsApiPath'], $remoteToken, $nextScheduled);
        if (!$ok) {
            logEntry("ERROR - Failed to update next scheduled sequence to: " . $GLOBALS['pluginsApiPath'] . "/updateNextScheduledSequence");
            return false;
        }
        logEntry_verbose("SUCCESS - Calling Plugins API to update next scheduled. Execution time: " . ((microtime(true) - $start_time) * 1000) . " ms");
        return true;
    }

    function highestVotedSequence($remoteToken) {
        $start_time = microtime(true);
        logEntry_verbose("Calling Plugins API to fetch highest voted sequence");
        $result = rf_http_rf_get_highest_voted($GLOBALS['pluginsApiPath'], $remoteToken);
        if ($result === null) {
            logEntry("ERROR - Failed to fetch highest voted sequence from: " . $GLOBALS['pluginsApiPath'] . "/highestVotedPlaylist");
            return (object)['winningPlaylist' => null, 'playlistIndex' => null, 'error' => true];
        }
        logEntry_verbose("SUCCESS - Calling Plugins API to fetch highest voted sequence. Execution time: " . ((microtime(true) - $start_time) * 1000) . " ms");
        return $result;
    }

    function nextPlaylistInQueue($remoteToken) {
        $start_time = microtime(true);
        logEntry_verbose("Calling Plugins API to fetch next requested sequence");
        $result = rf_http_rf_get_next_in_queue($GLOBALS['pluginsApiPath'], $remoteToken);
        if ($result === null) {
            logEntry("ERROR - Failed to fetch next playlist in queue from: " . $GLOBALS['pluginsApiPath'] . "/nextPlaylistInQueue");
            return (object)['nextPlaylist' => null, 'playlistIndex' => null, 'error' => true];
        }
        logEntry_verbose("SUCCESS - Calling Plugins API to fetch next requested sequence. Execution time: " . ((microtime(true) - $start_time) * 1000) . " ms");
        return $result;
    }

    // -------- Listener status file --------

    /**
     * Where the listener writes its status for the plugin page (status.php).
     * Lives next to the PID file in the plugin directory; gitignored.
     * Tests override with $GLOBALS['rfStatusFile'].
     */
    function rf_status_file(): string {
        return $GLOBALS['rfStatusFile'] ?? (dirname(__DIR__) . '/remote_falcon_status.json');
    }

    /**
     * Merge $patch into the listener status and rewrite the status file.
     * Written via a temp file + rename so the plugin page never reads a
     * half-written file. Never throws: status is best-effort and must not
     * disrupt the show loop. A failed write is logged once per failure
     * streak so an empty Listener Status panel has an explanation.
     */
    function rf_status_update(array $patch): void {
        $status = array_replace($GLOBALS['rfStatus'] ?? [], $patch);
        $status['updatedAt'] = time();
        $GLOBALS['rfStatus'] = $status;

        $json = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $path = rf_status_file();
        $tmp = $path . '.tmp';
        $ok = @file_put_contents($tmp, $json) !== false && @rename($tmp, $path);
        if (!$ok && empty($GLOBALS['rfStatusWriteFailed'])) {
            logEntry("WARNING - Could not write listener status to " . $path . ". The Listener Status panel on the plugin page will stay empty. Check that the plugin folder is writable.");
        }
        $GLOBALS['rfStatusWriteFailed'] = !$ok;
    }

    // -------- Viewer control mode --------

    /**
     * Mark the mode unconfirmed on listener (re)start so it is read again.
     * Until it is, the listener uses $fallbackMode: jukebox on a cold start
     * (as before), or the mode it had already confirmed when this is a
     * restart, so one failed read during a settings save can't knock a
     * working voting show back to jukebox.
     */
    function rf_reset_mode_state(string $fallbackMode = 'jukebox'): void {
        $GLOBALS['viewerControlMode'] = $fallbackMode;
        $GLOBALS['modeConfirmed'] = false;
        $GLOBALS['modeLastAttemptAt'] = 0;
        $GLOBALS['modeReadFailures'] = 0;
        $GLOBALS['emptyFetchSinceModeRead'] = false;
        rf_status_update([
            'viewerControlMode' => $fallbackMode,
            'modeConfirmed' => false,
            'modeCheckedAt' => null,
            'modeError' => null,
        ]);
    }

    /**
     * Read the viewer control mode from RF and apply it. On failure the
     * current mode is kept (jukebox if never read) and the failure is logged
     * once per streak, not on every retry.
     *
     * @param int $timeout Seconds. The main loop passes a short one; it only
     *                     calls this at a quiet moment (rf_is_quiet_moment).
     * @return bool True when the mode was read.
     */
    function refreshViewerControlMode($remoteToken, int $timeout = 10): bool {
        $now = time();
        $GLOBALS['modeLastAttemptAt'] = $now;
        $GLOBALS['emptyFetchSinceModeRead'] = false;

        $current = (string) ($GLOBALS['viewerControlMode'] ?? 'jukebox');
        $confirmed = !empty($GLOBALS['modeConfirmed']);
        $mode = rf_parse_viewer_control_mode(rf_http_rf_get_preferences($GLOBALS['pluginsApiPath'], $remoteToken, $timeout));

        if ($mode === null) {
            $failures = (int) ($GLOBALS['modeReadFailures'] ?? 0) + 1;
            $GLOBALS['modeReadFailures'] = $failures;
            if ($failures === 1) {
                if ($confirmed) {
                    logEntry("WARNING - Unable to re-check viewer control mode with Remote Falcon. Keeping '" . $current . "'.");
                } else {
                    logEntry("WARNING - Unable to fetch remote preferences. Using '" . $current . "' mode until Remote Falcon can be reached; retrying every " . RF_MODE_RETRY_SECONDS . " seconds.");
                    logEntry("Please verify your Remote Token is correct and the API is accessible.");
                }
            }
            rf_status_update(['modeError' => ['at' => $now, 'failures' => $failures]]);
            return false;
        }

        $failures = (int) ($GLOBALS['modeReadFailures'] ?? 0);
        $GLOBALS['modeReadFailures'] = 0;
        if (!$confirmed) {
            $retried = $failures === 0 ? "" : " (read after " . $failures . " failed attempt" . ($failures === 1 ? "" : "s") . ")";
            logEntry("Viewer Control Mode: " . $mode . $retried);
        } elseif ($mode !== $current) {
            logEntry("Viewer control mode changed: " . $current . " -> " . $mode);
        }
        $GLOBALS['viewerControlMode'] = $mode;
        $GLOBALS['modeConfirmed'] = true;
        rf_status_update([
            'viewerControlMode' => $mode,
            'modeConfirmed' => true,
            'modeCheckedAt' => $now,
            'modeError' => null,
        ]);
        return true;
    }

    /**
     * Note the outcome of a request/vote fetch: feeds the heartbeat, the
     * mode re-check trigger and the plugin page status.
     *
     * @param string $kind   "vote" or "request".
     * @param object $result Response from highestVotedSequence/nextPlaylistInQueue.
     */
    function rf_record_control_fetch(string $kind, $result): void {
        $now = time();
        $sequence = $kind === 'vote' ? ($result->winningPlaylist ?? null) : ($result->nextPlaylist ?? null);
        $GLOBALS['lastControlFetchAt'] = $now;
        if ($sequence === null) {
            $GLOBALS['emptyFetchSinceModeRead'] = true;
        }
        $fetch = [
            'at' => $now,
            'kind' => $kind,
            'sequence' => $sequence,
            'error' => !empty($result->error),
        ];
        if (rf_should_write_fetch_status($GLOBALS['rfStatus']['lastFetch'] ?? null, $fetch)) {
            rf_status_update(['lastFetch' => $fetch]);
        }
    }

    function rf_record_insert(string $sequence, bool $ok): void {
        rf_status_update(['lastInsert' => ['at' => time(), 'sequence' => $sequence, 'ok' => $ok]]);
    }

    // -------- Remote playlist sanity check --------

    /**
     * Check that the remote playlist is set and exists in FPP, so a broken
     * setup is a clear line in the log instead of an insert error on every
     * song. The listener repeats this until the playlist is found
     * (rf_should_check_remote_playlist); each warning is logged once, and the
     * status panel's warning clears as soon as the playlist turns up.
     *
     * @return bool|null true when found, false when unset or missing, null
     *                   when FPP didn't answer (nothing logged or changed).
     */
    function checkRemotePlaylist(string $remotePlaylist): ?bool {
        $found = false;
        if (trim($remotePlaylist) !== '') {
            $names = rf_http_fpp_get_playlist_names(rf_fpp_base_url());
            if ($names === null) {
                return null;
            }
            $found = in_array($remotePlaylist, $names, true);
        }
        $warning = rf_remote_playlist_warning($remotePlaylist, $found);
        if ($warning !== null && $warning !== ($GLOBALS['remotePlaylistWarningLogged'] ?? null)) {
            logEntry($warning);
        }
        $GLOBALS['remotePlaylistWarningLogged'] = $warning;
        rf_status_update(['remotePlaylist' => $remotePlaylist, 'remotePlaylistWarning' => $warning]);
        return $found;
    }

    // -------- Compatibility wrapper for legacy call sites --------

    function getNextSequence($mainPlaylist, $currentlyPlaying) {
        return rf_get_next_sequence($mainPlaylist, (string) $currentlyPlaying);
    }

    // -------- State-update orchestrators --------

    function updateCurrentlyPlaying($currentlyPlaying, $currentlyPlayingInRF, $remoteToken) {
        $newValue = rf_decide_currently_playing_update((string) $currentlyPlaying, (string) $currentlyPlayingInRF);
        if ($newValue !== null) {
            updateWhatsPlaying($newValue, $remoteToken);
            logEntry("Updated current playing sequence to " . $newValue);
            $GLOBALS['currentlyPlayingInRF'] = $newValue;
        }
    }

    function updateNextScheduledSequence($fppStatus, $currentlyPlaying, $nextScheduledInRF, $remoteToken) {
        if (!isset($fppStatus->current_playlist) || $fppStatus->current_playlist === null) {
            logEntry_verbose("Current playlist is null, skipping next scheduled sequence update");
            return;
        }
        if (!isset($fppStatus->current_playlist->playlist)) {
            logEntry_verbose("Current playlist name is not set, skipping next scheduled sequence update");
            return;
        }

        $currentPlaylist = $fppStatus->current_playlist->playlist;

        // Skip the FPP playlist fetch entirely when we're playing the user's
        // Remote Falcon playlist. In that case RF owns sequencing and we
        // wouldn't post the result anyway (rf_decide_next_scheduled_update
        // returns null). At ~1Hz polling during a show, this skips ~3,600
        // FPP HTTP calls/hour.
        if ((string) $currentPlaylist === (string) $GLOBALS['remotePlaylist']) {
            // No log line: this fires every poll while playing the RF
            // playlist (the most common state during a show), and a
            // verbose-level entry per tick swamps the log with noise.
            return;
        }

        // Cache playlist details for 60 seconds; FPP playlists rarely
        // change mid-show. Removes ~3,600 FPP HTTP calls per hour.
        $cacheKey = (string) $currentPlaylist;
        $now = microtime(true);
        $playlistDetails = rf_playlist_cache_get($cacheKey, $now, 60.0);
        if ($playlistDetails === null) {
            $playlistDetails = getPlaylistDetails(rawurlencode($currentPlaylist));
            if ($playlistDetails !== null) {
                rf_playlist_cache_put($cacheKey, $playlistDetails, $now);
            }
        }

        $nextScheduled = rf_decide_next_scheduled_update(
            $playlistDetails,
            (string) $currentPlaylist,
            (string) $currentlyPlaying,
            (string) $nextScheduledInRF,
            (string) $GLOBALS['remotePlaylist'],
            $fppStatus->current_playlist
        );
        if ($nextScheduled !== null) {
            updateNextScheduledSequenceInRf($nextScheduled, $remoteToken);
            logEntry("Updated next scheduled sequence to " . $nextScheduled);
            $GLOBALS['nextScheduledInRF'] = $nextScheduled;
        }
    }

    function clearNextScheduledSequence($remoteToken) {
        // Forget what was last sent along with clearing it in RF. Otherwise a
        // show restarted at the same spot computes the same "next" as before
        // the stop, updateNextScheduledSequence dedups it against the stale
        // memory, and RF stays blank until the song changes. "" is what a
        // freshly started listener holds (posts are trimmed, so " " and ""
        // reach RF identically).
        if (updateNextScheduledSequenceInRf(" ", $remoteToken)) {
            $GLOBALS['nextScheduledInRF'] = "";
        }
    }

    /**
     * Push what FPP is doing to RF's "currently playing" / "next scheduled"
     * for one listener tick. While playing, posts whatever changed. On the
     * first idle tick after playing, clears both in RF.
     *
     * @param bool $rfSequencesCleared Whether RF was already cleared for the
     *                                 current idle stretch.
     * @return bool The new $rfSequencesCleared for the next tick.
     */
    function syncShowStateToRf($fppStatus, $remoteToken, $rfSequencesCleared) {
        if ($fppStatus->status_name != "idle") {
            $currentlyPlaying = rf_extract_currently_playing($fppStatus);
            updateCurrentlyPlaying($currentlyPlaying, $GLOBALS['currentlyPlayingInRF'], $remoteToken);
            updateNextScheduledSequence($fppStatus, $currentlyPlaying, $GLOBALS['nextScheduledInRF'], $remoteToken);
            return false;
        }
        if (!$rfSequencesCleared) {
            updateCurrentlyPlaying(" ", $GLOBALS['currentlyPlayingInRF'], $remoteToken);
            clearNextScheduledSequence($remoteToken);
        }
        return true;
    }

    // -------- Queueing orchestration --------

    function doNonInterruptStuff($fppStatus, $requestFetchTime, $viewerControlMode, $additionalWaitTime, $remotePlaylist, $remoteToken) {
        $secondsRemaining = intVal($fppStatus->seconds_remaining ?? 0);
        $currentlyPlaying = rf_extract_currently_playing($fppStatus);

        if (rf_should_skip_non_interrupt_check(
                $currentlyPlaying,
                (string) ($GLOBALS['lastQueuedSequence'] ?? ''),
                time(),
                (int) ($GLOBALS['lastQueuedTime'] ?? 0),
                (int) $requestFetchTime,
                (int) $additionalWaitTime
            )) {
            logEntry_verbose("Already queued for current sequence, skipping. Time since queue: " . (time() - (int) ($GLOBALS['lastQueuedTime'] ?? 0)) . "s");
            return;
        }

        if (!rf_should_fetch_now($secondsRemaining, (int) $requestFetchTime)) {
            return;
        }

        $start_time = microtime(true);
        logEntry_verbose("Starting Non Interrupt Function");

        if ($viewerControlMode == "voting") {
            logEntry($requestFetchTime . " seconds remaining. Getting highest voted sequence.");
            $highestVotedSequence = highestVotedSequence($remoteToken);
            rf_record_control_fetch('vote', $highestVotedSequence);
            $winningSequence = $highestVotedSequence->winningPlaylist;
            $winningSequenceIndex = $highestVotedSequence->playlistIndex;
            if ($winningSequence != null) {
                logEntry("Queuing winning sequence " . $winningSequence . " at index " . $winningSequenceIndex);
                $inserted = insertPlaylistAfterCurrent(rawurlencode($remotePlaylist), $winningSequenceIndex);
                rf_record_insert((string) $winningSequence, $inserted);
            } else {
                logEntry("No votes");
            }
        } else {
            logEntry($requestFetchTime . " seconds remaining. Getting next request.");
            $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
            rf_record_control_fetch('request', $nextPlaylistInQueue);
            $nextSequence = $nextPlaylistInQueue->nextPlaylist;
            $nextSequenceIndex = $nextPlaylistInQueue->playlistIndex;
            if ($nextSequence != null) {
                logEntry("Queuing requested sequence " . $nextSequence . " at index " . $nextSequenceIndex);
                $inserted = insertPlaylistAfterCurrent(rawurlencode($remotePlaylist), $nextSequenceIndex);
                rf_record_insert((string) $nextSequence, $inserted);
            } else {
                logEntry("No requests");
            }
        }

        // Track that we've queued (or checked) for this sequence so the next
        // few iterations skip via rf_should_skip_non_interrupt_check.
        $GLOBALS['lastQueuedSequence'] = $currentlyPlaying;
        $GLOBALS['lastQueuedTime'] = time();

        $fppWaitTime = $requestFetchTime + $additionalWaitTime;
        logEntry("Sleeping for " . $fppWaitTime . " seconds.");
        sleep($fppWaitTime);

        logEntry_verbose("Completed Non Interrupt Function. Execution time: " . ((microtime(true) - $start_time) * 1000) . " ms");
    }

    function doInterruptStuff($fppStatus, $requestFetchTime, $viewerControlMode, $additionalWaitTime, $remotePlaylist, $remoteToken) {
        if (!isset($fppStatus->current_playlist) || $fppStatus->current_playlist == null) {
            return;
        }
        if (!isset($fppStatus->current_playlist->playlist)) {
            return;
        }
        $currentPlaylist = $fppStatus->current_playlist->playlist;
        if ($currentPlaylist == $GLOBALS['remotePlaylist']) {
            doNonInterruptStuff($fppStatus, $requestFetchTime, $viewerControlMode, $additionalWaitTime, $remotePlaylist, $remoteToken);
            return;
        }

        if (rf_should_skip_interrupt_check(
                time(),
                (int) ($GLOBALS['lastQueuedTime'] ?? 0),
                (int) $requestFetchTime,
                (int) $additionalWaitTime
            )) {
            logEntry_verbose("Recently interrupted, skipping. Time since last: " . (time() - (int) ($GLOBALS['lastQueuedTime'] ?? 0)) . "s");
            return;
        }

        $start_time = microtime(true);
        logEntry_verbose("Starting Interrupt Function");

        if ($viewerControlMode == "voting") {
            $highestVotedSequence = highestVotedSequence($remoteToken);
            rf_record_control_fetch('vote', $highestVotedSequence);
            $winningSequence = $highestVotedSequence->winningPlaylist;
            $winningSequenceIndex = $highestVotedSequence->playlistIndex;
            if ($winningSequence != null) {
                $inserted = insertPlaylistImmediate(rawurlencode($remotePlaylist), $winningSequenceIndex);
                rf_record_insert((string) $winningSequence, $inserted);
                logEntry("Playing winning sequence " . $winningSequence . " at index " . $winningSequenceIndex);
                $GLOBALS['lastQueuedSequence'] = $winningSequence;
                $GLOBALS['lastQueuedTime'] = time();
                $fppWaitTime = $requestFetchTime + $additionalWaitTime;
                logEntry("Sleeping for " . $fppWaitTime . " seconds.");
                sleep($fppWaitTime);
            }
        } else {
            $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
            rf_record_control_fetch('request', $nextPlaylistInQueue);
            $nextSequence = $nextPlaylistInQueue->nextPlaylist;
            $nextSequenceIndex = $nextPlaylistInQueue->playlistIndex;
            if ($nextSequence != null) {
                $inserted = insertPlaylistImmediate(rawurlencode($remotePlaylist), $nextSequenceIndex);
                rf_record_insert((string) $nextSequence, $inserted);
                logEntry("Playing requested sequence " . $nextSequence . " at index " . $nextSequenceIndex);
                $GLOBALS['lastQueuedSequence'] = $nextSequence;
                $GLOBALS['lastQueuedTime'] = time();
                $fppWaitTime = $requestFetchTime + $additionalWaitTime;
                logEntry("Sleeping for " . $fppWaitTime . " seconds.");
                sleep($fppWaitTime);
            }
        }

        logEntry_verbose("Completed Interrupt Function. Execution time: " . ((microtime(true) - $start_time) * 1000) . " ms");
    }
}
