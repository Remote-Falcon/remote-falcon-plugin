<?php
include_once "/opt/fpp/www/common.php";
include_once "/home/fpp/media/plugins/remote-falcon/baseurl.php";
$baseUrl = getBaseUrl();
$pluginName = basename(dirname(__FILE__));
$pluginPath = $settings['pluginDirectory']."/".$pluginName."/"; 
$logFile = $settings['logDirectory']."/".$pluginName.".log";
$pluginConfigFile = $settings['configDirectory'] . "/plugin." .$pluginName;
$pluginSettings = parse_ini_file($pluginConfigFile);

WriteSettingToFile("remote_fpp_enabled",urlencode("true"),$pluginName);
WriteSettingToFile("remote_fpp_restarting",urlencode("false"),$pluginName);

$pluginVersion = urldecode($pluginSettings['pluginVersion']);
echo "Starting Remote Falcon Plugin v" . $pluginVersion . "\n";
logEntry("Starting Remote Falcon Plugin v" . $pluginVersion);

$remoteToken = "";
$remotePlaylist = "";
$viewerControlMode = "";
$interruptSchedule = "";
$currentlyPlayingInRF = "";
$nextScheduledInRF= "";
$requestFetchTime = "";

$remoteToken = urldecode($pluginSettings['remoteToken']);
$remotePlaylist = urldecode($pluginSettings['remotePlaylist']);
logEntry("Remote Playlist: ".$remotePlaylist);
$remotePreferences = remotePreferences($remoteToken);
$viewerControlMode = $remotePreferences->viewerControlMode;
logEntry("Viewer Control Mode: " . $viewerControlMode);
$interruptSchedule = urldecode($pluginSettings['interrupt_schedule_enabled']);
logEntry("Interrupt Schedule: " . $interruptSchedule);
$interruptSchedule = $interruptSchedule == "true" ? true : false;
$requestFetchTime = intVal(urldecode($pluginSettings['requestFetchTime']));
logEntry("Request Fetch Time: " . $requestFetchTime);

while(true) {
  $pluginSettings = parse_ini_file($pluginConfigFile);
  $remoteFppEnabled = urldecode($pluginSettings['remote_fpp_enabled']);
  $remoteFppEnabled = $remoteFppEnabled == "true" ? true : false;
  $remoteFppRestarting = urldecode($pluginSettings['remote_fpp_restarting']);
  $remoteFppRestarting = $remoteFppRestarting == "true" ? true : false;

  if($remoteFppRestarting == 1) {
    WriteSettingToFile("remote_fpp_enabled",urlencode("true"),$pluginName);
    WriteSettingToFile("remote_fpp_restarting",urlencode("false"),$pluginName);

    echo "Restarting Remote Falcon Plugin v" . $pluginVersion . "\n";
    logEntry("Restarting Remote Falcon Plugin v" . $pluginVersion);
    $remoteToken = urldecode($pluginSettings['remoteToken']);
    $remotePlaylist = urldecode($pluginSettings['remotePlaylist']);
    logEntry("Remote Playlist: ".$remotePlaylist);
    $remotePreferences = remotePreferences($remoteToken);
    $viewerControlMode = $remotePreferences->viewerControlMode;
    logEntry("Viewer Control Mode: " . $viewerControlMode);
    $interruptSchedule = urldecode($pluginSettings['interrupt_schedule_enabled']);
    logEntry("Interrupt Schedule: " . $interruptSchedule);
    $interruptSchedule = $interruptSchedule == "true" ? true : false;
    $requestFetchTime = intVal(urldecode($pluginSettings['requestFetchTime']));
    logEntry("Request Fetch Time: " . $requestFetchTime);
  }

  if($remoteFppEnabled == 1) {
    $fppStatus = getFppStatus();
    $statusName = $fppStatus->status_name;
    if($statusName != "idle") {
      $currentlyPlaying = pathinfo($fppStatus->current_sequence, PATHINFO_FILENAME);
      if($currentlyPlaying == "") {
        //Might be media only, so check for current song
        $currentlyPlaying = pathinfo($fppStatus->current_song, PATHINFO_FILENAME);
      }
      updateCurrentlyPlaying($currentlyPlaying, $GLOBALS['currentlyPlayingInRF'], $remoteToken);
      updateNextScheduledSequence($fppStatus, $currentlyPlaying, $GLOBALS['nextScheduledInRF'], $remoteToken);

      //Do not interrupt schedule
      if($interruptSchedule != 1) {
        $secondsRemaining = intVal($fppStatus->seconds_remaining);
        if($secondsRemaining < $requestFetchTime) {
          logEntry($requestFetchTime . " seconds remaining, so fetching next request");
          if($viewerControlMode == "voting") {
            $highestVotedSequence = highestVotedSequence($remoteToken);
            $winningSequence = $highestVotedSequence->winningPlaylist;
            $winningSequenceIndex = $highestVotedSequence->playlistIndex;
            if($winningSequence != null) {
              if($winningSequenceIndex != 0 && $winningSequenceIndex != -1) {
                logEntry("Queuing winning sequence " . $winningSequence . " at index " . $winningSequenceIndex);
                insertPlaylistAfterCurrent(rawurlencode($remotePlaylist), $winningSequenceIndex);
                sleep($requestFetchTime);
                updateCurrentlyPlaying($winningSequence, $GLOBALS['currentlyPlayingInRF'], $remoteToken);
              }else {
                logEntry($winningSequence . " was not found in " . $remotePlaylist . " or has invalid index (" . $winningSequenceIndex . ")");
              }
            }else {
              logEntry("No votes");
              sleep($requestFetchTime);
            }
          }else {
            $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
            $nextSequence = $nextPlaylistInQueue->nextPlaylist;
            $nextSequenceIndex = $nextPlaylistInQueue->playlistIndex;
            if($nextSequence != null) {
              if($nextSequenceIndex != 0 && $nextSequenceIndex != -1) {
                logEntry("Queuing requested sequence " . $nextSequence . " at index " . $nextSequenceIndex);
                insertPlaylistAfterCurrent(rawurlencode($remotePlaylist), $nextSequenceIndex);
                sleep($requestFetchTime);
                updateCurrentlyPlaying($nextSequence, $GLOBALS['currentlyPlayingInRF'], $remoteToken);
              }else {
                logEntry($nextSequence . " was not found in " . $remotePlaylist . " or has invalid index (" . $nextSequenceIndex . ")");
              }
            }else {
              logEntry("No requests");
              sleep($requestFetchTime);
            }
          }
        }
      //Do interrupt schedule
      }else {
        if($viewerControlMode == "voting") {
          $highestVotedSequence = highestVotedSequence($remoteToken);
          $winningSequence = $highestVotedSequence->winningPlaylist;
          $winningSequenceIndex = $highestVotedSequence->playlistIndex;
          if($winningSequence != null) {
            if($winningSequenceIndex != 0 && $winningSequenceIndex != -1) {
              insertPlaylistImmediate(rawurlencode($remotePlaylist), $winningSequenceIndex);
              logEntry("Playing winning sequence " . $winningSequence . " at index " . $winningSequenceIndex);
              updateCurrentlyPlaying($winningSequence, $GLOBALS['currentlyPlayingInRF'], $remoteToken);
              holdForImmediatePlay();
            }else {
              logEntry($winningSequence . " was not found in " . $remotePlaylist . " or has invalid index (" . $winningSequenceIndex . ")");
            }
          }else {
            sleep(5);
          }
        }else {
          $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
          $nextSequence = $nextPlaylistInQueue->nextPlaylist;
          $nextSequenceIndex = $nextPlaylistInQueue->playlistIndex;
          if($nextSequence != null) {
            if($nextSequenceIndex != 0 && $nextSequenceIndex != -1) {
              insertPlaylistImmediate(rawurlencode($remotePlaylist), $nextSequenceIndex);
              logEntry("Playing requested sequence " . $nextSequence . " at index " . $nextSequenceIndex);
              updateCurrentlyPlaying($nextSequence, $GLOBALS['currentlyPlayingInRF'], $remoteToken);
              holdForImmediatePlay();
            }else {
              logEntry($nextSequence . " was not found in " . $remotePlaylist . " or has invalid index (" . $nextSequenceIndex . ")");
            }
          }else {
            sleep(5);
          }
        }
      }
    }else {
      updateCurrentlyPlaying(" ", $GLOBALS['currentlyPlayingInRF'], $remoteToken);
      clearNextScheduledSequence($remoteToken);
    }
  }

  usleep(250000);
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
  $mainPlaylist = $playlistDetails->mainPlaylist;
  $nextScheduled = getNextSequence($mainPlaylist, $currentlyPlaying);
  if($nextScheduled != $nextScheduledInRF && $currentPlaylist != $GLOBALS['remotePlaylist']) {
    updateNextScheduledSequenceInRf($nextScheduled, $remoteToken);
    logEntry("Updated next scheduled sequence to " . $currentlyPlaying);
    $GLOBALS['nextScheduledInRF'] = $nextScheduled;
  }
}

function clearNextScheduledSequence($remoteToken) {
  updateNextScheduledSequenceInRf(" ", $remoteToken);
}

function getNextSequence($mainPlaylist, $currentlyPlaying) {
  $nextScheduled = "";
  for ($i = 0; $i < count($mainPlaylist); $i++) {
    if(pathinfo($mainPlaylist[$i]->sequenceName, PATHINFO_FILENAME) == $currentlyPlaying) {
      if($i+1 == count($mainPlaylist)) {
        $nextScheduled = $mainPlaylist[0]->sequenceName;
      }else {
        $nextScheduled = $mainPlaylist[$i+1]->sequenceName;
      }
    }
  }
  return pathinfo($nextScheduled, PATHINFO_FILENAME);
}

function holdForImmediatePlay() {
  sleep(5);
  $fppStatus = getFppStatus();
  $secondsRemaining = intVal($fppStatus->seconds_remaining);
  logEntry("Sitting tight for " . $secondsRemaining . " seconds");
  while($secondsRemaining > 1) {
    $fppStatus = getFppStatus();
    $secondsRemaining = intVal($fppStatus->seconds_remaining);
    usleep(250000);
  }
}

function remotePreferences($remoteToken) {
  $url = $GLOBALS['baseUrl'] . "/remotefalcon/api/remotePreferences";
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
  $result=file_get_contents("http://127.0.0.1/api/fppd/status");
  return json_decode( $result );
}

function updateWhatsPlaying($currentlyPlaying, $remoteToken) {
  $url = $GLOBALS['baseUrl'] . "/remotefalcon/api/updateWhatsPlaying";
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
}

function updateNextScheduledSequenceInRf($nextScheduled, $remoteToken) {
  $url = $GLOBALS['baseUrl'] . "/remotefalcon/api/updateNextScheduledSequence";
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
  $url = $GLOBALS['baseUrl'] . "/remotefalcon/api/highestVotedPlaylist";
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

function nextPlaylistInQueue($remoteToken) {
  $url = $GLOBALS['baseUrl'] . "/remotefalcon/api/nextPlaylistInQueue?updateQueue=true";
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

function logEntry($data) {

	global $logFile,$myPid;

	$data = $_SERVER['PHP_SELF']." : [".$myPid."] ".$data;
	
	$logWrite= fopen($logFile, "a") or die("Unable to open file!");
	fwrite($logWrite, date('Y-m-d h:i:s A',time()).": ".$data."\n");
	fclose($logWrite);
}

?>
