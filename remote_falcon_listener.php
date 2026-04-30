<?php
$PLUGIN_VERSION = "2026.01.02.01";

include_once "/opt/fpp/www/common.php";
require_once __DIR__ . "/lib/listener_logic.php";
require_once __DIR__ . "/lib/listener_http.php";
$pluginName = basename(dirname(__FILE__));
$pluginPath = $settings['pluginDirectory']."/".$pluginName."/"; 
$logFile = $settings['logDirectory']."/".$pluginName."-listener.log";
$pluginConfigFile = $settings['configDirectory'] . "/plugin." .$pluginName;
$pluginSettings = parse_ini_file($pluginConfigFile);

logEntry("Starting Remote Falcon Plugin v" . $PLUGIN_VERSION);

if ($pluginSettings === false) {
  logEntry("ERROR - Unable to read plugin config file at startup: " . $pluginConfigFile);
  $pluginSettings = array();
}

WriteSettingToFile("pluginVersion",urlencode($PLUGIN_VERSION),$pluginName);

//Set defaults here since this runs before the plugin page is visited
if (strlen(urldecode($pluginSettings['remotePlaylist']))<1){
  WriteSettingToFile("remotePlaylist",urlencode(""),$pluginName);
}
if (strlen(urldecode($pluginSettings['interruptSchedule']))<1){
  WriteSettingToFile("interruptSchedule",urlencode("false"),$pluginName);
}
if (strlen(urldecode($pluginSettings['remoteToken']))<1){
  WriteSettingToFile("remoteToken",urlencode(""),$pluginName);
}
if (strlen(urldecode($pluginSettings['requestFetchTime']))<1){
  WriteSettingToFile("requestFetchTime",urlencode("3"),$pluginName);
}
if (strlen(urldecode($pluginSettings['additionalWaitTime']))<1){
  WriteSettingToFile("additionalWaitTime",urlencode("0"),$pluginName);
}
if (strlen(urldecode($pluginSettings['fppStatusCheckTime']))<1){
  WriteSettingToFile("fppStatusCheckTime",urlencode("1"),$pluginName);
}
if (strlen(urldecode($pluginSettings['pluginsApiPath']))<1){
  WriteSettingToFile("pluginsApiPath",urlencode("https://remotefalcon.com/remote-falcon-plugins-api"),$pluginName);
}
if (strlen(urldecode($pluginSettings['verboseLogging']))<1){
  WriteSettingToFile("verboseLogging",urlencode("false"),$pluginName);
}
if (strlen(urldecode($pluginSettings['remoteFalconListenerEnabled']))<1){
  WriteSettingToFile("remoteFalconListenerEnabled",urlencode("true"),$pluginName);
}
if (strlen(urldecode($pluginSettings['remoteFalconListenerRestarting']))<1){
  WriteSettingToFile("remoteFalconListenerRestarting",urlencode("false"),$pluginName);
}

$remoteToken = "";
$remotePlaylist = "";
$viewerControlMode = "";
$interruptSchedule = "";
$currentlyPlayingInRF = "";
$nextScheduledInRF= "";
$requestFetchTime = "";
$rfSequencesCleared = false;
$additionalWaitTime = "";
$pluginsApiPath = "";
$verboseLogging = false;
$lastQueuedSequence = "";
$lastQueuedTime = 0;

$pluginsApiPath = urldecode($pluginSettings['pluginsApiPath']);
logEntry("Plugins API Path: " . $pluginsApiPath);
$remoteToken = urldecode($pluginSettings['remoteToken']);
$remotePlaylist = urldecode($pluginSettings['remotePlaylist']);
logEntry("Remote Playlist: ".$remotePlaylist);

// Safely fetch remote preferences with error handling
$remotePreferences = remotePreferences($remoteToken);
if ($remotePreferences === null || !isset($remotePreferences->viewerControlMode)) {
  logEntry("WARNING - Unable to fetch remote preferences. Using default 'jukebox' mode.");
  logEntry("Please verify your Remote Token is correct and the API is accessible.");
  $viewerControlMode = "jukebox"; // Default to jukebox mode
} else {
  $viewerControlMode = $remotePreferences->viewerControlMode;
  logEntry("Viewer Control Mode: " . $viewerControlMode);
}

$interruptSchedule = urldecode($pluginSettings['interruptSchedule']);
logEntry("Interrupt Schedule: " . $interruptSchedule);
$interruptSchedule = $interruptSchedule == "true" ? true : false;
$requestFetchTime = intVal(urldecode($pluginSettings['requestFetchTime']));
logEntry("Request Fetch Time: " . $requestFetchTime);
$additionalWaitTime = intVal(urldecode($pluginSettings['additionalWaitTime']));
logEntry("Additional Wait Time: " . $additionalWaitTime);
$rawStatusCheckTime = urldecode($pluginSettings['fppStatusCheckTime']);
$fppStatusCheckTime = rf_clamp_status_check_time($rawStatusCheckTime);
if (floatval($rawStatusCheckTime) < 0.1) {
  logEntry("WARNING - fppStatusCheckTime ($rawStatusCheckTime) too low, clamping to 0.1");
}
logEntry("FPP Status Check Time: " . $fppStatusCheckTime . " (" . $fppStatusCheckTime * 1000000 . " microseconds)");
$verboseLogging = urldecode($pluginSettings['verboseLogging']);
logEntry("Verbose Logging: " . $verboseLogging);
$GLOBALS['verboseLogging'] = ($verboseLogging === "true");

while(true) {
  $pluginSettings = parse_ini_file($pluginConfigFile);

  if ($pluginSettings === false) {
    logEntry("ERROR - Unable to read plugin config file: " . $pluginConfigFile . ". Retrying in 5 seconds.");
    sleep(5);
    continue;
  }

  $remoteFppEnabled = urldecode($pluginSettings['remoteFalconListenerEnabled']);
  $remoteFppEnabled = $remoteFppEnabled == "true" ? true : false;
  $remoteFppRestarting = urldecode($pluginSettings['remoteFalconListenerRestarting']);
  $remoteFppRestarting = $remoteFppRestarting == "true" ? true : false;

  if($remoteFppRestarting == 1) {
    WriteSettingToFile("remoteFalconListenerEnabled",urlencode("true"),$pluginName);
    WriteSettingToFile("remoteFalconListenerRestarting",urlencode("false"),$pluginName);

    logEntry("Restarting Remote Falcon Plugin v" . $PLUGIN_VERSION);
    $pluginsApiPath = urldecode($pluginSettings['pluginsApiPath']);
    logEntry("Plugins API Path: " . $pluginsApiPath);
    $remoteToken = urldecode($pluginSettings['remoteToken']);
    $remotePlaylist = urldecode($pluginSettings['remotePlaylist']);
    logEntry("Remote Playlist: ".$remotePlaylist);

    // Safely fetch remote preferences with error handling
    $remotePreferences = remotePreferences($remoteToken);
    if ($remotePreferences === null || !isset($remotePreferences->viewerControlMode)) {
      logEntry("WARNING - Unable to fetch remote preferences. Using default 'jukebox' mode.");
      logEntry("Please verify your Remote Token is correct and the API is accessible.");
      $viewerControlMode = "jukebox"; // Default to jukebox mode
    } else {
      $viewerControlMode = $remotePreferences->viewerControlMode;
      logEntry("Viewer Control Mode: " . $viewerControlMode);
    }

    $interruptSchedule = urldecode($pluginSettings['interruptSchedule']);
    logEntry("Interrupt Schedule: " . $interruptSchedule);
    $interruptSchedule = $interruptSchedule == "true" ? true : false;
    $requestFetchTime = intVal(urldecode($pluginSettings['requestFetchTime']));
    logEntry("Request Fetch Time: " . $requestFetchTime);
    $additionalWaitTime = intVal(urldecode($pluginSettings['additionalWaitTime']));
    logEntry("Additional Wait Time: " . $additionalWaitTime);
    $rawStatusCheckTime = urldecode($pluginSettings['fppStatusCheckTime']);
    $fppStatusCheckTime = rf_clamp_status_check_time($rawStatusCheckTime);
    if (floatval($rawStatusCheckTime) < 0.1) {
      logEntry("WARNING - fppStatusCheckTime ($rawStatusCheckTime) too low, clamping to 0.1");
    }
    logEntry("FPP Status Check Time: " . $fppStatusCheckTime . " (" . $fppStatusCheckTime * 1000000 . " microseconds)");
    $verboseLogging = urldecode($pluginSettings['verboseLogging']);
    logEntry("Verbose Logging: " . $verboseLogging);
    $GLOBALS['verboseLogging'] = ($verboseLogging === "true");
  }

  if($remoteFppEnabled == 1) {
    logEntry_verbose("Getting FPP Status");
    $fppStatus = getFppStatus();
    if($fppStatus != null && $fppStatus != false) {
      $statusName = $fppStatus->status_name;
      if($statusName != "idle") {
        $rfSequencesCleared = false;
        $currentlyPlaying = pathinfo($fppStatus->current_sequence, PATHINFO_FILENAME);
        if($currentlyPlaying == "") {
          //Might be media only, so check for current song
          $currentlyPlaying = pathinfo($fppStatus->current_song, PATHINFO_FILENAME);
        }
        updateCurrentlyPlaying($currentlyPlaying, $GLOBALS['currentlyPlayingInRF'], $remoteToken);
        updateNextScheduledSequence($fppStatus, $currentlyPlaying, $GLOBALS['nextScheduledInRF'], $remoteToken);

        if($interruptSchedule != 1) {
          doNonInterruptStuff($fppStatus, $requestFetchTime, $viewerControlMode, $additionalWaitTime, $remotePlaylist, $remoteToken);
        }else {
          doInterruptStuff($fppStatus, $requestFetchTime, $viewerControlMode, $additionalWaitTime, $remotePlaylist, $remoteToken);
        }
      }else {
        if($rfSequencesCleared == 0) {
          updateCurrentlyPlaying(" ", $GLOBALS['currentlyPlayingInRF'], $remoteToken);
          clearNextScheduledSequence($remoteToken);
          $rfSequencesCleared = true;
        }
      }
    }else {
      logEntry("FPPD is not running!");
      sleep(5);
    }
  }

  usleep($fppStatusCheckTime * 1000000);
}

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

  if (rf_should_fetch_now($secondsRemaining, (int) $requestFetchTime)) {
    $start_time = microtime(true);
    logEntry_verbose("Starting Non Interrupt Function");

    if($viewerControlMode == "voting") {
      logEntry($requestFetchTime . " seconds remaining. Getting highest voted sequence.");
      $highestVotedSequence = highestVotedSequence($remoteToken);
      $winningSequence = $highestVotedSequence->winningPlaylist;
      $winningSequenceIndex = $highestVotedSequence->playlistIndex;
      if($winningSequence != null) {
        logEntry("Queuing winning sequence " . $winningSequence . " at index " . $winningSequenceIndex);
        insertPlaylistAfterCurrent(rawurlencode($remotePlaylist), $winningSequenceIndex);

        // Track that we've queued for this sequence
        $GLOBALS['lastQueuedSequence'] = $currentlyPlaying;
        $GLOBALS['lastQueuedTime'] = time();

        $fppWaitTime = $requestFetchTime + $additionalWaitTime;
        logEntry("Sleeping for " . $fppWaitTime . " seconds.");
        sleep($fppWaitTime);
      }else {
        logEntry("No votes");

        // Track that we've checked (even with no result) to prevent re-checking
        $GLOBALS['lastQueuedSequence'] = $currentlyPlaying;
        $GLOBALS['lastQueuedTime'] = time();

        $fppWaitTime = $requestFetchTime + $additionalWaitTime;
        logEntry("Sleeping for " . $fppWaitTime . " seconds.");
        sleep($fppWaitTime);
      }
    }else {
      logEntry($requestFetchTime . " seconds remaining. Getting next request.");
      $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
      $nextSequence = $nextPlaylistInQueue->nextPlaylist;
      $nextSequenceIndex = $nextPlaylistInQueue->playlistIndex;
      if($nextSequence != null) {
        logEntry("Queuing requested sequence " . $nextSequence . " at index " . $nextSequenceIndex);
        insertPlaylistAfterCurrent(rawurlencode($remotePlaylist), $nextSequenceIndex);

        // Track that we've queued for this sequence
        $GLOBALS['lastQueuedSequence'] = $currentlyPlaying;
        $GLOBALS['lastQueuedTime'] = time();

        $fppWaitTime = $requestFetchTime + $additionalWaitTime;
        logEntry("Sleeping for " . $fppWaitTime . " seconds.");
        sleep($fppWaitTime);
      }else {
        logEntry("No requests");

        // Track that we've checked (even with no result) to prevent re-checking
        $GLOBALS['lastQueuedSequence'] = $currentlyPlaying;
        $GLOBALS['lastQueuedTime'] = time();

        $fppWaitTime = $requestFetchTime + $additionalWaitTime;
        logEntry("Sleeping for " . $fppWaitTime . " seconds.");
        sleep($fppWaitTime);
      }
    }
    $end_time = microtime(true);
    $execution_time = ($end_time - $start_time);
    logEntry_verbose("Completed Non Interrupt Function. Execution time: " . $execution_time * 1000 . " ms");
  }
}

function doInterruptStuff($fppStatus, $requestFetchTime, $viewerControlMode, $additionalWaitTime, $remotePlaylist, $remoteToken) {
  if($fppStatus->current_playlist != null) {
    $currentPlaylist = $fppStatus->current_playlist->playlist;
    if($currentPlaylist != $GLOBALS['remotePlaylist']) {
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
      if($viewerControlMode == "voting") {
        $highestVotedSequence = highestVotedSequence($remoteToken);
        $winningSequence = $highestVotedSequence->winningPlaylist;
        $winningSequenceIndex = $highestVotedSequence->playlistIndex;
        if($winningSequence != null) {
          insertPlaylistImmediate(rawurlencode($remotePlaylist), $winningSequenceIndex);
          logEntry("Playing winning sequence " . $winningSequence . " at index " . $winningSequenceIndex);

          // Track the interrupt time
          $GLOBALS['lastQueuedSequence'] = $winningSequence;
          $GLOBALS['lastQueuedTime'] = time();

          $fppWaitTime = $requestFetchTime + $additionalWaitTime;
          logEntry("Sleeping for " . $fppWaitTime . " seconds.");
          sleep($fppWaitTime);
        }
      }else {
        $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
        $nextSequence = $nextPlaylistInQueue->nextPlaylist;
        $nextSequenceIndex = $nextPlaylistInQueue->playlistIndex;
        if($nextSequence != null) {
          insertPlaylistImmediate(rawurlencode($remotePlaylist), $nextSequenceIndex);
          logEntry("Playing requested sequence " . $nextSequence . " at index " . $nextSequenceIndex);

          // Track the interrupt time
          $GLOBALS['lastQueuedSequence'] = $nextSequence;
          $GLOBALS['lastQueuedTime'] = time();

          $fppWaitTime = $requestFetchTime + $additionalWaitTime;
          logEntry("Sleeping for " . $fppWaitTime . " seconds.");
          sleep($fppWaitTime);
        }
      }
      $end_time = microtime(true);
      $execution_time = ($end_time - $start_time);
      logEntry_verbose("Completed Interrupt Function. Execution time: " . $execution_time * 1000 . " ms");
    }else {
      doNonInterruptStuff($fppStatus, $requestFetchTime, $viewerControlMode, $additionalWaitTime, $remotePlaylist, $remoteToken);
    }
  }
}

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
  $playlistDetails = getPlaylistDetails(rawurlencode($currentPlaylist));

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

// Thin wrapper around rf_get_next_sequence (lib/listener_logic.php) to keep
// the existing call site stable. The pure logic is unit-tested in tests/.
function getNextSequence($mainPlaylist, $currentlyPlaying) {
  return rf_get_next_sequence($mainPlaylist, (string) $currentlyPlaying);
}

// FPP localhost is hardcoded for now; perf branch may make it configurable.
const RF_FPP_BASE_URL = "http://127.0.0.1";

function remotePreferences($remoteToken) {
  $result = rf_http_rf_get_preferences($GLOBALS['pluginsApiPath'], $remoteToken);
  if ($result === null) {
    logEntry("ERROR - Failed to fetch remote preferences from: " . $GLOBALS['pluginsApiPath'] . "/remotePreferences");
  }
  return $result;
}

function getFppStatus() {
  $result = rf_http_fpp_get_status(RF_FPP_BASE_URL);
  if ($result === null) {
    logEntry_verbose("ERROR - Failed to get FPP status");
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

function insertPlaylistImmediate($remotePlaylistEncoded, $index) {
  $ok = rf_http_fpp_insert_immediate(RF_FPP_BASE_URL, $remotePlaylistEncoded, (int) $index);
  if (!$ok) {
    logEntry("ERROR - Failed to insert playlist immediate: " . rawurldecode($remotePlaylistEncoded) . " at index " . $index);
    return false;
  }
  logEntry_verbose("SUCCESS - Inserted playlist immediate");
  return true;
}

function insertPlaylistAfterCurrent($remotePlaylistEncoded, $index) {
  $ok = rf_http_fpp_insert_after_current(RF_FPP_BASE_URL, $remotePlaylistEncoded, (int) $index);
  if (!$ok) {
    logEntry("ERROR - Failed to insert playlist after current: " . rawurldecode($remotePlaylistEncoded) . " at index " . $index);
    return false;
  }
  logEntry_verbose("SUCCESS - Inserted playlist after current");
  return true;
}

function getPlaylistDetails($remotePlaylistEncoded) {
  $result = rf_http_fpp_get_playlist(RF_FPP_BASE_URL, $remotePlaylistEncoded);
  if ($result === null) {
    logEntry_verbose("ERROR - Failed to get playlist details for: " . rawurldecode($remotePlaylistEncoded));
  }
  return $result;
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

function logEntry($data) {

	global $logFile;

  $logWrite = @fopen($logFile, "a");
  if ($logWrite === false) {
    error_log("Remote Falcon listener cannot open log file: " . $logFile . " | Message: " . $data);
    return;
  }

	fwrite($logWrite, date('Y-m-d h:i:s A',time()).": ".$data."\n");
	fclose($logWrite);
}

function logEntry_verbose($data) {
  if(!isset($GLOBALS['verboseLogging']) || $GLOBALS['verboseLogging'] !== true) {
    return;
  }

  global $logFile;
  
  $logWrite = @fopen($logFile, "a");
  if ($logWrite === false) {
    error_log("Remote Falcon listener cannot open log file: " . $logFile . " | Message: " . $data);
    return;
  }

  fwrite($logWrite, date('Y-m-d h:i:s A',time()).": ".$data."\n");
  fclose($logWrite);
}

?>
