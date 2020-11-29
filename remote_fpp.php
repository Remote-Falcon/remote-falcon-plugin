<?php
include_once "/opt/fpp/www/common.php";
include_once "/home/fpp/media/plugins/remote-falcon/baseurl.php";
$baseUrl = getBaseUrl();
$pluginName = basename(dirname(__FILE__));
$pluginPath = $settings['pluginDirectory']."/".$pluginName."/"; 

$logFile = $settings['logDirectory']."/".$pluginName.".log";
$pluginConfigFile = $settings['configDirectory'] . "/plugin." .$pluginName;
$pluginSettings = parse_ini_file($pluginConfigFile);

$pluginVersion = urldecode($pluginSettings['pluginVersion']);
$remoteFppEnabled = urldecode($pluginSettings['remote_fpp_enabled']);
$remoteFppEnabled = $remoteFppEnabled == "true" ? true : false;

if($remoteFppEnabled == 1) {
  echo "Starting Remote Falcon Plugin v" . $pluginVersion . "\n";
  logEntry("Starting Remote Falcon Plugin v" . $pluginVersion);

  $remoteToken = urldecode($pluginSettings['remoteToken']);
  $remotePlaylist = urldecode($pluginSettings['remotePlaylist']);
  $remotePlaylistEncoded = rawurlencode($remotePlaylist);
  $currentlyPlayingInRF = "";

  logEntry("Remote Playlist: ".$remotePlaylist);
  $playlistDetails = getPlaylistDetails($remotePlaylistEncoded);
  $remotePlaylistSequences = $playlistDetails->mainPlaylist;

  $viewerControlMode = "";
  $remotePreferences = remotePreferences($remoteToken);
  $viewerControlMode = $remotePreferences->viewerControlMode;
  logEntry("Viewer Control Mode: " . $viewerControlMode);
  $interruptSchedule = urldecode($pluginSettings['interrupt_schedule_enabled']);
  logEntry("Interrupt Schedule: " . $interruptSchedule);
  $interruptSchedule = $interruptSchedule == "true" ? true : false;

  $currentSchedule = null;
  $fppScheduleStartTime = null;
  $fppScheduleEndTime = null;

  while(true) {
    $fppStatus = getFppStatus();
    
    if($fppStatus->scheduler->status=="playing") {
      $fppScheduleStartTime = $fppStatus->scheduler->currentPlaylist->scheduledStartTimeStr;
      $fppScheduleEndTime = $fppStatus->scheduler->currentPlaylist->actualEndTimeStr;
    }
    
    preSchedulePurge($fppScheduleStartTime, $remoteToken, $logFile);

    $currentlyPlaying = $fppStatus->current_sequence;
    $currentlyPlaying = pathinfo($currentlyPlaying, PATHINFO_FILENAME);
    $statusName = $fppStatus->status_name;

    if($currentlyPlaying != $currentlyPlayingInRF) {
      updateWhatsPlaying($currentlyPlaying, $remoteToken);
      logEntry("Updated current playing sequence to " . $currentlyPlaying);
      $currentlyPlayingInRF = $currentlyPlaying;
    }

    if($statusName != "idle" && !isScheduleDone($fppScheduleEndTime)) {
      //Do not interrupt schedule
      if($interruptSchedule != 1) {
        $secondsRemaining = intVal($fppStatus->seconds_remaining);
        if($secondsRemaining < 3) {
          logEntry("3 seconds remaining, so fetching next request");
          if($viewerControlMode == "voting") {
            $highestVotedSequence = highestVotedSequence($remoteToken);
            $winningSequence = $highestVotedSequence->winningPlaylist;
            $winningSequenceIndex = $highestVotedSequence->playlistIndex;
            if($winningSequence != null) {
              if($winningSequenceIndex != 0 && $winningSequenceIndex != -1) {
                logEntry("Queuing winning sequence " . $winningSequence . " at index " . $winningSequenceIndex);
                insertPlaylistAfterCurrent($remotePlaylistEncoded, $winningSequenceIndex);
                sleep(4);
              }else {
                logEntry($winningSequence . " was not found in " . $remotePlaylist . " or has invalid index (" . $winningSequenceIndex . ")");
              }
            }else {
              logEntry("No votes");
              sleep(4);
            }
          }else {
            $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
            $nextSequence = $nextPlaylistInQueue->nextPlaylist;
            $nextSequenceIndex = $nextPlaylistInQueue->playlistIndex;
            if($nextSequence != null) {
              if($nextSequenceIndex != 0 && $nextSequenceIndex != -1) {
                logEntry("Queuing requested sequence " . $nextSequence . " at index " . $nextSequenceIndex);
                insertPlaylistAfterCurrent($remotePlaylistEncoded, $nextSequenceIndex);
                sleep(4);
                updatePlaylistQueue($remoteToken);
              }else {
                logEntry($nextSequence . " was not found in " . $remotePlaylist . " or has invalid index (" . $nextSequenceIndex . ")");
              }
            }else {
              logEntry("No requests");
              sleep(4);
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
              insertPlaylistImmediate($remotePlaylistEncoded, $winningSequenceIndex);
              logEntry("Playing winning sequence " . $winningSequence . " at index " . $winningSequenceIndex);
              updateWhatsPlaying($winningSequence, $remoteToken);
              logEntry("Updated current playing sequence to " . $winningSequence);
              $currentlyPlayingInRF = $winningSequence;
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
              insertPlaylistImmediate($remotePlaylistEncoded, $nextSequenceIndex);
              updatePlaylistQueue($remoteToken);
              logEntry("Playing requested sequence " . $nextSequence . " at index " . $nextSequenceIndex);
              updateWhatsPlaying($nextSequence, $remoteToken);
              logEntry("Updated current playing sequence to " . $nextSequence);
              $currentlyPlayingInRF = $nextSequence;
              holdForImmediatePlay();
            }else {
              logEntry($nextSequence . " was not found in " . $remotePlaylist . " or has invalid index (" . $nextSequenceIndex . ")");
            }
          }else {
            sleep(5);
          }
        }
      }
    }
    usleep(250000);
  }
}else {
  logEntry("Remote Falcon is disabled");
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
  $url = $baseUrl . "/remotefalcon/api/remotePreferences";
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
  $url = $baseUrl . "/remotefalcon/api/updateWhatsPlaying";
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

function preSchedulePurge($fppScheduleStartTime, $remoteToken, $logFile) {
  $currentTime = date("H:i:s");
  $fppScheduleStartTime = strtotime($fppScheduleStartTime);
  $fppScheduleStartTime = date("H:i:s", $fppScheduleStartTime);
  if($currentTime == $fppScheduleStartTime) {
    logEntry("Purging queue and votes");
    $url = $baseUrl . "/remotefalcon/api/purgeQueue";
    $options = array(
      'http' => array(
        'method'  => 'DELETE',
        'header'=>  "Content-Type: application/json; charset=UTF-8\r\n" .
                    "Accept: application/json\r\n" .
                    "remotetoken: $remoteToken\r\n"
        )
    );
    $context = stream_context_create( $options );
    $result = file_get_contents( $url, false, $context );
    $url = $baseUrl . "/remotefalcon/api/resetAllVotes";
    $options = array(
      'http' => array(
        'method'  => 'DELETE',
        'header'=>  "Content-Type: application/json; charset=UTF-8\r\n" .
                    "Accept: application/json\r\n" .
                    "remotetoken: $remoteToken\r\n"
        )
    );
    $context = stream_context_create( $options );
    $result = file_get_contents( $url, false, $context );
    logEntry("Purged");
    usleep(250000);
  }
}

function isScheduleDone($fppScheduleEndTime) {
  $currentTime = date("H:i");
  $fppScheduleEndTime = strtotime($fppScheduleEndTime);
  $fppScheduleEndTime = date("H:i", $fppScheduleEndTime);
  if($fppScheduleEndTime == "00:00" && $currentTime == "00:00") {
    return true;
  }
  if($fppScheduleEndTime != "00:00" && $currentTime >= $fppScheduleEndTime) {
    return true;
  }
  return false;
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
  $url = $baseUrl . "/remotefalcon/api/highestVotedPlaylist";
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
  $url = $baseUrl . "/remotefalcon/api/nextPlaylistInQueue";
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

function updatePlaylistQueue($remoteToken) {
  $url = $baseUrl . "/remotefalcon/api/updatePlaylistQueue";
  $options = array(
    'http' => array(
      'method'  => 'POST',
      'header'=>  "Content-Type: application/json; charset=UTF-8\r\n" .
                  "Accept: application/json\r\n" .
                  "remotetoken: $remoteToken\r\n"
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
}

function logEntry($data) {

	global $logFile,$myPid;

	$data = $_SERVER['PHP_SELF']." : [".$myPid."] ".$data;
	
	$logWrite= fopen($logFile, "a") or die("Unable to open file!");
	fwrite($logWrite, date('Y-m-d h:i:s A',time()).": ".$data."\n");
	fclose($logWrite);
}

?>
