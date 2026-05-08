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

    function getFppStatus() {
        $result = rf_http_fpp_get_status(rf_fpp_base_url());
        if ($result === null) {
            logEntry_verbose("ERROR - Failed to get FPP status");
        }
        return $result;
    }

    function getPlaylistDetails($remotePlaylistEncoded) {
        $result = rf_http_fpp_get_playlist(rf_fpp_base_url(), $remotePlaylistEncoded);
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

    function remotePreferences($remoteToken) {
        $result = rf_http_rf_get_preferences($GLOBALS['pluginsApiPath'], $remoteToken);
        if ($result === null) {
            logEntry("ERROR - Failed to fetch remote preferences from: " . $GLOBALS['pluginsApiPath'] . "/remotePreferences");
        }
        return $result;
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
            return (object)['winningPlaylist' => null, 'playlistIndex' => null];
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
            return (object)['nextPlaylist' => null, 'playlistIndex' => null];
        }
        logEntry_verbose("SUCCESS - Calling Plugins API to fetch next requested sequence. Execution time: " . ((microtime(true) - $start_time) * 1000) . " ms");
        return $result;
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
            (string) $GLOBALS['remotePlaylist']
        );
        if ($nextScheduled !== null) {
            updateNextScheduledSequenceInRf($nextScheduled, $remoteToken);
            logEntry("Updated next scheduled sequence to " . $nextScheduled);
            $GLOBALS['nextScheduledInRF'] = $nextScheduled;
        }
    }

    function clearNextScheduledSequence($remoteToken) {
        updateNextScheduledSequenceInRf(" ", $remoteToken);
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
            $winningSequence = $highestVotedSequence->winningPlaylist;
            $winningSequenceIndex = $highestVotedSequence->playlistIndex;
            if ($winningSequence != null) {
                logEntry("Queuing winning sequence " . $winningSequence . " at index " . $winningSequenceIndex);
                insertPlaylistAfterCurrent(rawurlencode($remotePlaylist), $winningSequenceIndex);
            } else {
                logEntry("No votes");
            }
        } else {
            logEntry($requestFetchTime . " seconds remaining. Getting next request.");
            $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
            $nextSequence = $nextPlaylistInQueue->nextPlaylist;
            $nextSequenceIndex = $nextPlaylistInQueue->playlistIndex;
            if ($nextSequence != null) {
                logEntry("Queuing requested sequence " . $nextSequence . " at index " . $nextSequenceIndex);
                insertPlaylistAfterCurrent(rawurlencode($remotePlaylist), $nextSequenceIndex);
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
            $winningSequence = $highestVotedSequence->winningPlaylist;
            $winningSequenceIndex = $highestVotedSequence->playlistIndex;
            if ($winningSequence != null) {
                insertPlaylistImmediate(rawurlencode($remotePlaylist), $winningSequenceIndex);
                logEntry("Playing winning sequence " . $winningSequence . " at index " . $winningSequenceIndex);
                $GLOBALS['lastQueuedSequence'] = $winningSequence;
                $GLOBALS['lastQueuedTime'] = time();
                $fppWaitTime = $requestFetchTime + $additionalWaitTime;
                logEntry("Sleeping for " . $fppWaitTime . " seconds.");
                sleep($fppWaitTime);
            }
        } else {
            $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
            $nextSequence = $nextPlaylistInQueue->nextPlaylist;
            $nextSequenceIndex = $nextPlaylistInQueue->playlistIndex;
            if ($nextSequence != null) {
                insertPlaylistImmediate(rawurlencode($remotePlaylist), $nextSequenceIndex);
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
