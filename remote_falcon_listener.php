<?php
$PLUGIN_VERSION = "2024.11.29.1";

include_once "/opt/fpp/www/common.php";
$pluginName = basename(dirname(__FILE__));
$pluginPath = $settings['pluginDirectory']."/".$pluginName."/"; 
$logFile = $settings['logDirectory']."/".$pluginName."-listener.log";
$pluginConfigFile = $settings['configDirectory'] . "/plugin." .$pluginName;
$pluginSettings = parse_ini_file($pluginConfigFile);

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
  WriteSettingToFile("pluginsApiPath",urlencode("https://remotefalcon.com/remotefalcon/api"),$pluginName);
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

logEntry("Starting Remote Falcon Plugin v" . $PLUGIN_VERSION);

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

$pluginsApiPath = urldecode($pluginSettings['pluginsApiPath']);
logEntry("Plugins API Path: " . $pluginsApiPath);
$remoteToken = urldecode($pluginSettings['remoteToken']);
$remotePlaylist = urldecode($pluginSettings['remotePlaylist']);
logEntry("Remote Playlist: ".$remotePlaylist);
$remotePreferences = remotePreferences($remoteToken);
$viewerControlMode = $remotePreferences->viewerControlMode;
logEntry("Viewer Control Mode: " . $viewerControlMode);
$interruptSchedule = urldecode($pluginSettings['interruptSchedule']);
logEntry("Interrupt Schedule: " . $interruptSchedule);
$interruptSchedule = $interruptSchedule == "true" ? true : false;
$requestFetchTime = intVal(urldecode($pluginSettings['requestFetchTime']));
logEntry("Request Fetch Time: " . $requestFetchTime);
$additionalWaitTime = intVal(urldecode($pluginSettings['additionalWaitTime']));
logEntry("Additional Wait Time: " . $additionalWaitTime);
$fppStatusCheckTime = floatval(urldecode($pluginSettings['fppStatusCheckTime']));
logEntry("FPP Status Check Time: " . $fppStatusCheckTime . " (" . $fppStatusCheckTime * 1000000 . " microseconds)");
$verboseLogging = urldecode($pluginSettings['verboseLogging']);
logEntry("Verbose Logging: " . $verboseLogging);
$GLOBALS['verboseLogging'] = $verboseLogging == "true" ? true : false;

while(true) {
  $pluginSettings = parse_ini_file($pluginConfigFile);
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
    $remotePreferences = remotePreferences($remoteToken);
    $viewerControlMode = $remotePreferences->viewerControlMode;
    logEntry("Viewer Control Mode: " . $viewerControlMode);
    $interruptSchedule = urldecode($pluginSettings['interruptSchedule']);
    logEntry("Interrupt Schedule: " . $interruptSchedule);
    $interruptSchedule = $interruptSchedule == "true" ? true : false;
    $requestFetchTime = intVal(urldecode($pluginSettings['requestFetchTime']));
    logEntry("Request Fetch Time: " . $requestFetchTime);
    $additionalWaitTime = intVal(urldecode($pluginSettings['additionalWaitTime']));
    logEntry("Additional Wait Time: " . $additionalWaitTime);
    $fppStatusCheckTime = floatval(urldecode($pluginSettings['fppStatusCheckTime']));
    logEntry("FPP Status Check Time: " . $fppStatusCheckTime . " (" . $fppStatusCheckTime * 1000000 . " microseconds)");
    $verboseLogging = urldecode($pluginSettings['verboseLogging']);
    logEntry("Verbose Logging: " . $verboseLogging);
    $GLOBALS['verboseLogging'] = $verboseLogging == "true" ? true : false;
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
  $secondsRemaining = intVal($fppStatus->seconds_remaining);
  if($secondsRemaining < $requestFetchTime) {
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
        $fppWaitTime = $requestFetchTime + $additionalWaitTime;
        logEntry("Sleeping for " . $fppWaitTime . " seconds.");
        sleep($fppWaitTime);
      }else {
        logEntry("No votes");
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
        $fppWaitTime = $requestFetchTime + $additionalWaitTime;
        logEntry("Sleeping for " . $fppWaitTime . " seconds.");
        sleep($fppWaitTime);
      }else {
        logEntry("No requests");
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
      $start_time = microtime(true);
      logEntry_verbose("Starting Interrupt Function");
      if($viewerControlMode == "voting") {
        $highestVotedSequence = highestVotedSequence($remoteToken);
        $winningSequence = $highestVotedSequence->winningPlaylist;
        $winningSequenceIndex = $highestVotedSequence->playlistIndex;
        if($winningSequence != null) {
          insertPlaylistImmediate(rawurlencode($remotePlaylist), $winningSequenceIndex);
          logEntry("Playing winning sequence " . $winningSequence . " at index " . $winningSequenceIndex);
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
  if($currentlyPlaying != $currentlyPlayingInRF) {
    updateWhatsPlaying($currentlyPlaying, $remoteToken);
    logEntry("Updated current playing sequence to " . $currentlyPlaying);
    $GLOBALS['currentlyPlayingInRF'] = $currentlyPlaying;
  }
}

function updateNextScheduledSequence($fppStatus, $currentlyPlaying, $nextScheduledInRF, $remoteToken) {
  $currentPlaylist = $fppStatus->current_playlist->playlist;
  $playlistDetails = getPlaylistDetails(rawurlencode($currentPlaylist));
  if($playlistDetails != null && $playlistDetails != '') {
    $mainPlaylist = $playlistDetails->mainPlaylist;
    if($mainPlaylist != null && $mainPlaylist != "" && count($mainPlaylist) > 0) {
      $nextScheduled = getNextSequence($mainPlaylist, $currentlyPlaying);
      if($nextScheduled != $nextScheduledInRF && $currentPlaylist != $GLOBALS['remotePlaylist']) {
        updateNextScheduledSequenceInRf($nextScheduled, $remoteToken);
        logEntry("Updated next scheduled sequence to " . $nextScheduled);
        $GLOBALS['nextScheduledInRF'] = $nextScheduled;
      }
    }
  }
}

function clearNextScheduledSequence($remoteToken) {
  updateNextScheduledSequenceInRf(" ", $remoteToken);
}

function getNextSequence($mainPlaylist, $currentlyPlaying) {
  $nextScheduled = "";
  for ($i = 0; $i < count($mainPlaylist); $i++) {
    if(isset($mainPlaylist[$i]->sequenceName) && pathinfo($mainPlaylist[$i]->sequenceName, PATHINFO_FILENAME) == $currentlyPlaying) {
      if($i+1 == count($mainPlaylist)) {
        $nextScheduled = $mainPlaylist[0]->sequenceName;
      }else {
        if(isset($mainPlaylist[$i+1]->sequenceName)) {
          $nextScheduled = $mainPlaylist[$i+1]->sequenceName;
        }
      }
    }
  }
  return pathinfo($nextScheduled, PATHINFO_FILENAME);
}

function remotePreferences($remoteToken) {
  $url = $GLOBALS['pluginsApiPath'] . "/remotePreferences";
  $options = array(
    'http' => array(
      'method'  => 'GET',
      'header'=>  "remotetoken: $remoteToken\r\n"
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  return json_decode( $result );
}

function getFppStatus() {
  $result=file_get_contents("http://127.0.0.1/api/system/status");
  return json_decode( $result );
}

function updateWhatsPlaying($currentlyPlaying, $remoteToken) {
  $start_time = microtime(true);
  logEntry_verbose("Calling Plugins API to update what's playing");
  $url = $GLOBALS['pluginsApiPath'] . "/updateWhatsPlaying";
  $data = array(
    'playlist' => trim($currentlyPlaying)
  );
  $options = array(
    'http' => array(
      'method'  => 'POST',
      'content' => json_encode( $data ),
      'header'=>  "Content-Type: application/json; charset=UTF-8\r\n" .
                  "Accept: application/json\r\n" .
                  "remotetoken: $remoteToken\r\n"
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  $end_time = microtime(true);
  $execution_time = ($end_time - $start_time);
  logEntry_verbose("SUCCESS - Calling Plugins API to update what's playing. Execution time: " . $execution_time * 1000 . " ms");
}

function updateNextScheduledSequenceInRf($nextScheduled, $remoteToken) {
  $start_time = microtime(true);
  logEntry_verbose("Calling Plugins API to update next scheduled");
  $url = $GLOBALS['pluginsApiPath'] . "/updateNextScheduledSequence";
  $data = array(
    'sequence' => trim($nextScheduled)
  );
  $options = array(
    'http' => array(
      'method'  => 'POST',
      'content' => json_encode( $data ),
      'header'=>  "Content-Type: application/json; charset=UTF-8\r\n" .
                  "Accept: application/json\r\n" .
                  "remotetoken: $remoteToken\r\n"
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  $end_time = microtime(true);
  $execution_time = ($end_time - $start_time);
  logEntry_verbose("SUCCESS - Calling Plugins API to update next scheduled. Execution time: " . $execution_time * 1000 . " ms");
}

function insertPlaylistImmediate($remotePlaylistEncoded, $index) { 
  $url = "http://127.0.0.1/api/command/Insert%20Playlist%20Immediate/" . $remotePlaylistEncoded . "/" . $index . "/" . $index;
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
}

function insertPlaylistAfterCurrent($remotePlaylistEncoded, $index) {
  $url = "http://127.0.0.1/api/command/Insert%20Playlist%20After%20Current/" . $remotePlaylistEncoded . "/" . $index . "/" . $index;
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
}

function stopGracefully() {
  $url = "http://127.0.0.1/api/playlists/stopgracefully";
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
}

function getPlaylistDetails($remotePlaylistEncoded) {
  $url = "http://127.0.0.1/api/playlist/" . $remotePlaylistEncoded;
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  return json_decode( $result );
}

function highestVotedSequence($remoteToken) {
  $start_time = microtime(true);
  logEntry_verbose("Calling Plugins API to fetch highest voted sequence");
  $url = $GLOBALS['pluginsApiPath'] . "/highestVotedPlaylist";
  $options = array(
    'http' => array(
      'method'  => 'GET',
      'header'=>  "remotetoken: $remoteToken\r\n"
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  $end_time = microtime(true);
  $execution_time = ($end_time - $start_time);
  logEntry_verbose("SUCCESS - Calling Plugins API to fetch highest voted sequence. Execution time: " . $execution_time * 1000 . " ms");
  return json_decode( $result );
}

function nextPlaylistInQueue($remoteToken) {
  $start_time = microtime(true);
  logEntry_verbose("Calling Plugins API to fetch next requested sequence");
  $url = $GLOBALS['pluginsApiPath'] . "/nextPlaylistInQueue?updateQueue=true";
  $options = array(
    'http' => array(
      'method'  => 'GET',
      'header'=>  "remotetoken: $remoteToken\r\n"
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  $execution_time = ($end_time - $start_time);
  logEntry_verbose("SUCCESS - Calling Plugins API to fetch next requested sequence. Execution time: " . $execution_time * 1000 . " ms");
  return json_decode( $result );
  return json_decode( $result );
}

function logEntry($data) {

	global $logFile,$myPid;

	$data = $_SERVER['PHP_SELF']." : [".$myPid."] ".$data;
	
	$logWrite= fopen($logFile, "a") or die("Unable to open file!");
	fwrite($logWrite, date('Y-m-d h:i:s A',time()).": ".$data."\n");
	fclose($logWrite);
}

function logEntry_verbose($data) {
  if($GLOBALS['verboseLogging'] == 1) {
    global $logFile,$myPid;
  
    $data = $_SERVER['PHP_SELF']." : [".$myPid."] ".$data;
    
    $logWrite= fopen($logFile, "a") or die("Unable to open file!");
    fwrite($logWrite, date('Y-m-d h:i:s A',time()).": ".$data."\n");
    fclose($logWrite);
  }
}

?>
