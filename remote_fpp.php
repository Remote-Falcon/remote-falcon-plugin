<?php
include_once "/opt/fpp/www/common.php";
$pluginName = basename(dirname(__FILE__));
$pluginPath = $settings['pluginDirectory']."/".$pluginName."/"; 
$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";//is this needed?
$logFile = $settings['logDirectory']."/".$pluginName.".log";

echo "Starting Remote Falcon Plugin version 4.6.0\n";
logEntry("Starting Remote Falcon Plugin version 4.6.0"); //Probably should pull the version in from the settings file?

//$remoteToken = trim(file_get_contents($pluginPath."remote_token.txt"));
$remotePlaylist = trim(file_get_contents($pluginPath."remote_playlist.txt"));
$remotePlaylistEncoded = str_replace(' ', '%20', $remotePlaylist);
$currentlyPlayingInRF = "";
logEntry($pluginPath."remote_token.txt");
logEntry("Remote Token = ".$remoteToken);
logEntry("Remote Playlist = ".$remotePlaylist);
logEntry("Remote Playlist Encoded = ".$remotePlaylistEncoded);
$playlistDetails = getPlaylistDetails($remotePlaylistEncoded);
$remotePlaylistSequences = $playlistDetails->mainPlaylist;

$viewerControlMode = "";
$remotePreferences = remotePreferences($remoteToken);
$viewerControlMode = $remotePreferences->viewerControlMode;
logEntry("Viewer Control Mode: " . $viewerControlMode);
$interruptSchedule = trim(file_get_contents("$pluginPath/interrupt_schedule_enabled.txt"));
logEntry("Interrupt Schedule: " . $interruptSchedule);
$interruptSchedule = $interruptSchedule == "true" ? true : false;

$currentSchedule = null;
$fppScheduleStartTime = null;
$fppScheduleEndTime = null;

while(true) {
  $fppSchedule = getFppSchedule();
  $fppSchedule = getScheduleToUse($fppSchedule);
  if($fppSchedule != null && $currentSchedule != $fppSchedule) {
    $fppScheduleStartTime = $fppSchedule->startTime;
    $fppScheduleEndTime = $fppSchedule->endTime;
    logEntry("Starting Schedule for " . $fppSchedule->startDate . " from " . $fppSchedule->startTime . " to " . $fppSchedule->endTime);
    $currentSchedule = $fppSchedule;
  }
  
  preSchedulePurge($fppScheduleStartTime, $remoteToken, $logFile);

  $currentlyPlaying = "";
  $fppStatus = getFppStatus();
  $currentlyPlaying = $fppStatus->current_sequence;
  $currentlyPlaying = pathinfo($currentlyPlaying, PATHINFO_FILENAME);
  $statusName = $fppStatus->status_name;

  if($currentlyPlaying != $currentlyPlayingInRF) {
    updateWhatsPlaying($currentlyPlaying, $remoteToken);
    logEntry("Updated current playing sequence to " . $currentlyPlaying);
    $currentlyPlayingInRF = $currentlyPlaying;
  }

  backupScheduleShutdown($fppScheduleEndTime, $statusName, $logFile);

  if($statusName != "idle" && !isScheduleDone($fppScheduleEndTime)) {
    //Do not interrupt schedule
    if($interruptSchedule != 1) {
      $fppStatus = getFppStatus();
      $secondsRemaining = intVal($fppStatus->seconds_remaining);
      if($secondsRemaining < 1) {
        logEntry("Fetching next sequence");
        if($viewerControlMode == "voting") {
          $highestVotedSequence = highestVotedSequence($remoteToken);
          $winningSequence = $highestVotedSequence->winningPlaylist;
          if($winningSequence != null) {
            $index = getSequenceIndex($remotePlaylistSequences, $winningSequence);
            if($index != 0) {
              insertPlaylistAfterCurrent($remotePlaylistEncoded, $index);
              logEntry("Queuing winning sequence " . $winningSequence);
            }
          }else {
            logEntry("No votes");
          }
        }else {
          $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
          $nextSequence = $nextPlaylistInQueue->nextPlaylist;
          if($nextSequence != null) {
            $index = getSequenceIndex($remotePlaylistSequences, $nextSequence);
            if($index != 0) {
              insertPlaylistAfterCurrent($remotePlaylistEncoded, $index);
              updatePlaylistQueue($remoteToken);
             logEntry("Queuing requested sequence " . $nextSequence);
            }
          }else {
            logEntry("No requests");
          }
        }
        sleep(5);
      }
    //Do interrupt schedule
    }else {
      if($viewerControlMode == "voting") {
        $highestVotedSequence = highestVotedSequence($remoteToken);
        $winningSequence = $highestVotedSequence->winningPlaylist;
        if($winningSequence != null) {
          $index = getSequenceIndex($remotePlaylistSequences, $winningSequence);
          if($index != 0) {
            insertPlaylistImmediate($remotePlaylistEncoded, $index);
            logEntry("Playing winning sequence " . $winningSequence);
            updateWhatsPlaying($winningSequence, $remoteToken);
            logEntry("Updated current playing sequence to " . $winningSequence);
            $currentlyPlayingInRF = $winningSequence;
            sleep(5);
            holdForImmediatePlay();
          }
        }else {
          sleep(5);
        }
      }else {
        $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
        $nextSequence = $nextPlaylistInQueue->nextPlaylist;
        if($nextSequence != null) {
          $index = getSequenceIndex($remotePlaylistSequences, $nextSequence);
          if($index != 0) {
            insertPlaylistImmediate($remotePlaylistEncoded, $index);
            updatePlaylistQueue($remoteToken);
            logEntry("Playing requested sequence " . $nextSequence);
            updateWhatsPlaying($nextSequence, $remoteToken);
            logEntry("Updated current playing sequence to " . $nextSequence);
            $currentlyPlayingInRF = $nextSequence;
            sleep(5);
            holdForImmediatePlay();
          }
        }else {
          sleep(5);
        }
      }
    }
  }
  usleep(250000);
}

function holdForImmediatePlay() {
  $fppStatus = getFppStatus();
  $secondsRemaining = intVal($fppStatus->seconds_remaining);
  while($secondsRemaining > 1) {
    $fppStatus = getFppStatus();
    $secondsRemaining = intVal($fppStatus->seconds_remaining);
    usleep(250000);
  }
}

function remotePreferences($remoteToken) {
  $url = "https://remotefalcon.com/remotefalcon/api/remotePreferences";
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
  $url = "http://127.0.0.1/api/fppd/status";
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  return json_decode( $result );
}

function updateWhatsPlaying($currentlyPlaying, $remoteToken) {
  $url = "https://remotefalcon.com/remotefalcon/api/updateWhatsPlaying";
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
    $url = "https://remotefalcon.com/remotefalcon/api/purgeQueue";
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
    $url = "https://remotefalcon.com/remotefalcon/api/resetAllVotes";
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

function backupScheduleShutdown($fppScheduleEndTime, $statusName, $logFile) {
  if(isScheduleDone($fppScheduleEndTime) && $statusName != "stopping gracefully" && $statusName != "idle") {
    logEntry("Schedule is done, so stopping gracefully");
    stopGracefully();
    sleep(60);
  }
}

function getFppSchedule() {
  $url = "http://127.0.0.1/api/schedule";
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  return json_decode( $result );
}

function getScheduleToUse($fppSchedule) {
  $dayOfWeek = date("l");
  $currentDate = date("Y-m-d");
  $currentTime = date("H:i:s");
  foreach ($fppSchedule as $schedule) {
    $isCorrectDate = false;
    if($currentDate >= $schedule->startDate && $currentDate <= $schedule->endDate) {
      $isCorrectDate = true;
    }
    $isCorrectTime = false;
    if($currentTime >= $schedule->startTime && $currentTime <= $schedule->endTime) {
      $isCorrectTime = true;
    }
    if($schedule->enabled == 1 && $isCorrectDate && $isCorrectTime) {
      $fppScheduleDay = $schedule->day;
      if($fppScheduleDay == 7) {
        return $schedule;
      }
      switch ($dayOfWeek) {
        case "Monday":
          if($fppScheduleDay == 1 || $fppScheduleDay == 8 || $fppScheduleDay == 10 || $fppScheduleDay == 12) {
            return $schedule;
          }
          break;
        case "Tuesday":
          if($fppScheduleDay == 2 || $fppScheduleDay == 8 || $fppScheduleDay == 11 || $fppScheduleDay == 12) {
            return $schedule;
          }
          break;
        case "Wednesday":
          if($fppScheduleDay == 3 || $fppScheduleDay == 8 || $fppScheduleDay == 10 || $fppScheduleDay == 12) {
            return $schedule;
          }
          break;
        case "Thursday":
          if($fppScheduleDay == 4 || $fppScheduleDay == 8 || $fppScheduleDay == 11 || $fppScheduleDay == 12) {
            return $schedule;
          }
          break;
        case "Friday":
          if($fppScheduleDay == 5 || $fppScheduleDay == 8 || $fppScheduleDay == 10 || $fppScheduleDay == 13) {
            return $schedule;
          }
          break;
        case "Saturday":
          if($fppScheduleDay == 6 || $fppScheduleDay == 9 || $fppScheduleDay == 13) {
            return $schedule;
          }
          break;
        default:
          if($fppScheduleDay == 0 || $fppScheduleDay == 9 || $fppScheduleDay == 12) {
            return $schedule;
          }
          break;
      }
    }
  }
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
  $url = "https://remotefalcon.com/remotefalcon/api/highestVotedPlaylist";
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
  $url = "https://remotefalcon.com/remotefalcon/api/nextPlaylistInQueue";
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
  $url = "https://remotefalcon.com/remotefalcon/api/updatePlaylistQueue";
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

function getSequenceIndex($remotePlaylistSequences, $sequenceToPlay) {
  $index = 1;
  $validSequence = false;
  foreach ($remotePlaylistSequences as $sequence) {
    if(property_exists($sequence, 'sequenceName')) {
      $sequenceName = $sequence->sequenceName;
      $sequenceName = pathinfo($sequenceName, PATHINFO_FILENAME);
      if($sequenceName == $sequenceToPlay) {
        $validSequence = true;
        break;
      }
    }
    if(property_exists($sequence, 'mediaName')) {
      $sequenceName = $sequence->mediaName;
      $sequenceName = pathinfo($sequenceName, PATHINFO_FILENAME);
      if($sequenceName == $sequenceToPlay) {
        $validSequence = true;
        break;
      }
    }
    $index++;
  }
  if(!$validSequence) {
    $index = 0;
  }
  return $index;
}

function logEntry($data) {

	global $logFile,$myPid;

	$data = $_SERVER['PHP_SELF']." : [".$myPid."] ".$data;
	
	$logWrite= fopen($logFile, "a") or die("Unable to open file!");
	fwrite($logWrite, date('Y-m-d h:i:s A',time()).": ".$data."\n");
	fclose($logWrite);
}

?>
