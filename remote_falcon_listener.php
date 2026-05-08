<?php
$PLUGIN_VERSION = "2026.01.02.01";

include_once "/opt/fpp/www/common.php";
require_once __DIR__ . "/lib/listener_logic.php";
require_once __DIR__ . "/lib/listener_http.php";
require_once __DIR__ . "/lib/listener_log.php";
require_once __DIR__ . "/lib/listener_actions.php";
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

  $sleepSeconds = $fppStatusCheckTime;

  if($remoteFppEnabled == 1) {
    logEntry_verbose("Getting FPP Status");
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
