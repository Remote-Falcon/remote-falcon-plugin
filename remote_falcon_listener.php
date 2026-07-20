<?php
$PLUGIN_VERSION = "2026.06.15.01";

// /opt/fpp/www/common.php is FPP's PHP "common functions" file, authored
// for the web-UI context: it emits HTML markup (script tags, settings-
// as-JS-globals, etc.) as a top-level side effect, and reads $_SERVER
// keys like REQUEST_URI that don't exist in a CLI context. In our
// listener context, postStart.sh redirects stdout/stderr to the listener
// log, so both the markup AND the warnings about missing $_SERVER keys
// would otherwise pollute the log on every startup.
//
// Buffer stdout to discard the HTML, and use @include to suppress the
// CLI-context warnings. We still get the function/variable definitions
// we actually need (WriteSettingToFile, the $settings array, etc.).
ob_start();
@include_once "/opt/fpp/www/common.php";
ob_end_clean();

require_once __DIR__ . "/lib/listener_logic.php";
require_once __DIR__ . "/lib/listener_http.php";
require_once __DIR__ . "/lib/listener_log.php";
require_once __DIR__ . "/lib/listener_actions.php";
require_once __DIR__ . "/lib/sync_builder.php";
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
if (!isset($pluginSettings['autoSyncPlaylist']) || strlen(urldecode($pluginSettings['autoSyncPlaylist']))<1){
  WriteSettingToFile("autoSyncPlaylist",urlencode("false"),$pluginName);
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

// Auto Sync Playlist (#13) watcher state.
$autoSyncPlaylistName = null;
$autoSyncLastMtime = null;
$autoSyncChangedAt = null;
$autoSyncMissingWarned = false;

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

$lastIniMtime = null;

// V17 heartbeat — emit a /fppHeartbeat POST every ~30s independent of show
// state so the dashboard can render "plugin online" + gap windows. First tick
// fires immediately (lastHeartbeatTs starts at 0) so the "alive" signal lands
// right after a restart instead of 30s later.
$lastHeartbeatTs = 0;
$heartbeatIntervalSeconds = 30;

while(true) {
  // Re-parse the settings INI only when its mtime has changed. parse_ini_file
  // is far cheaper than HTTP, but at 1Hz polling for a multi-hour show it
  // adds up. WriteSettingToFile always bumps mtime, so flag changes still
  // trigger a re-parse on the very next tick.
  if (rf_ini_should_reparse($pluginConfigFile, $lastIniMtime, time()) || !isset($pluginSettings) || $pluginSettings === false) {
    $pluginSettings = parse_ini_file($pluginConfigFile);
    if ($pluginSettings === false) {
      logEntry("ERROR - Unable to read plugin config file: " . $pluginConfigFile . ". Retrying in 5 seconds.");
      sleep(5);
      continue;
    }
    $lastIniMtime = rf_ini_current_mtime($pluginConfigFile);
  }

  $remoteFppEnabled = urldecode($pluginSettings['remoteFalconListenerEnabled']);
  $remoteFppEnabled = $remoteFppEnabled == "true" ? true : false;
  $remoteFppRestarting = urldecode($pluginSettings['remoteFalconListenerRestarting']);
  $remoteFppRestarting = $remoteFppRestarting == "true" ? true : false;

  if($remoteFppRestarting == 1) {
    WriteSettingToFile("remoteFalconListenerEnabled",urlencode("true"),$pluginName);
    WriteSettingToFile("remoteFalconListenerRestarting",urlencode("false"),$pluginName);
    // Mirror our own writes into the in-memory copy and force a re-parse on
    // the next tick. filemtime is second-granular: when the clear-write above
    // lands in the same second as the parse that saw restarting=true, the
    // mtime check alone reports "unchanged", the stale in-memory flag re-fires
    // this branch every tick, and WriteSettingToFile (idempotent, value
    // already false) never bumps the mtime again — an infinite restart loop.
    $pluginSettings['remoteFalconListenerEnabled'] = urlencode("true");
    $pluginSettings['remoteFalconListenerRestarting'] = urlencode("false");
    $lastIniMtime = null;

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

  $sleepSeconds = $fppStatusCheckTime;

  if($remoteFppEnabled == 1) {
    $nowTs = time();
    if (($nowTs - $lastHeartbeatTs) >= $heartbeatIntervalSeconds) {
      fppHeartbeat($remoteToken);
      $lastHeartbeatTs = $nowTs;
    }

    // Auto Sync Playlist (#13): watch the synced playlist's JSON for edits
    // and re-sync to RF once the file has sat quiet for 30s (every new save
    // resets the window, so an editing session coalesces into one sync).
    // Off by default via the autoSyncPlaylist setting. Re-syncs the SAME
    // playlist, so no listener restart and no remotePlaylist rewrite.
    $autoSyncEnabled = isset($pluginSettings['autoSyncPlaylist'])
        && urldecode($pluginSettings['autoSyncPlaylist']) === "true";
    if ($autoSyncEnabled && $remotePlaylist != "") {
      $playlistDir = isset($settings['playlistDirectory']) ? $settings['playlistDirectory'] : '/home/fpp/media/playlists';
      $playlistJson = $playlistDir . '/' . $remotePlaylist . '.json';
      if ($autoSyncPlaylistName !== $remotePlaylist) {
        // Synced playlist switched (UI or command) — start fresh, don't
        // treat the new file's mtime as a pending change. Log the watched
        // path so a wrong playlistDirectory is visible in the first
        // screenful of the log instead of failing silently.
        $autoSyncPlaylistName = $remotePlaylist;
        $autoSyncLastMtime = null;
        $autoSyncChangedAt = null;
        $autoSyncMissingWarned = false;
        logEntry("Auto Sync: watching " . $playlistJson);
      }
      // Playlist saves come from FPP's apache (another process); clear the
      // stat cache for this path or a stale cached mtime can hide the edit.
      clearstatcache(false, $playlistJson);
      $currMtime = @filemtime($playlistJson);
      if ($currMtime === false && !$autoSyncMissingWarned) {
        logEntry("WARNING - Auto Sync: playlist file not found: " . $playlistJson);
        $autoSyncMissingWarned = true;
      } else if ($currMtime !== false) {
        $autoSyncMissingWarned = false;
      }
      $autoSyncDecision = rf_auto_sync_decision($autoSyncLastMtime, $autoSyncChangedAt, $currMtime, $nowTs, 30);
      $autoSyncLastMtime = $autoSyncDecision['lastMtime'];
      $autoSyncChangedAt = $autoSyncDecision['changedAt'];
      if ($autoSyncDecision['sync']) {
        // Dispatch out-of-process so this ~1Hz tick never blocks on the
        // sync's HTTP round-trips (an in-tick sync could stall status
        // polling and the 30s heartbeat — 2026-07-16 release review). The
        // runner logs its own outcome to this log and flock-serializes
        // itself, so overlapping dispatches skip rather than stack.
        logEntry("Auto Sync: '" . $remotePlaylist . "' changed; dispatching background sync");
        exec("php " . escapeshellarg($pluginPath . "auto_sync_runner.php") . " " . escapeshellarg($remotePlaylist) . " > /dev/null 2>&1 &");
      }
    }

    // Per-tick "Getting FPP Status" log was previously emitted at verbose
    // level. At 1 Hz over a multi-hour show that's thousands of lines/hour
    // of noise that obscures actual events. Removed in perf 2.5; the poll
    // cadence is implicit in the timestamps of the lines that DO fire.
    $fppStatus = getFppStatus();
    if($fppStatus != null && $fppStatus != false) {
      $statusName = $fppStatus->status_name;
      $sleepSeconds = rf_next_poll_seconds((string) $statusName, (float) $fppStatusCheckTime);
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
      continue;
    }
  }

  usleep((int) ($sleepSeconds * 1000000));
}

?>
