<?php
$pluginPath = "/home/fpp/media/plugins/remote-falcon";
$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";

$logFile = fopen("/home/fpp/media/logs/RF-". date("Y-m-d") . ".log", "a");
$oldLogFile = "/home/fpp/media/logs/RF-". date("Y-m-d", strtotime("-3 days", strtotime(date("Y-m-d")))) . ".log";
if (file_exists($oldLogFile)) {
  unlink($oldLogFile);
}

echo "Starting Remote Falcon Plugin\n";
writeLog($logFile, "Starting Remote Falcon Plugin");

$remoteToken = trim(file_get_contents("$pluginPath/remote_token.txt"));
$remotePlaylist = trim(file_get_contents("$pluginPath/remote_playlist.txt"));
$remotePlaylistEncoded = str_replace(' ', '%20', $remotePlaylist);
$currentlyPlayingInRF = "";

$playlistDetails = getPlaylistDetails($remotePlaylistEncoded);
$remotePlaylistSequences = $playlistDetails->mainPlaylist;

$viewerControlMode = "";
$remotePreferences = remotePreferences($remoteToken);
$viewerControlMode = $remotePreferences->viewerControlMode;
$interruptSchedule = trim(file_get_contents("$pluginPath/interrupt_schedule_enabled.txt"));
$interruptSchedule = $interruptSchedule == "true" ? true : false;

writeLog($logFile, "Viewer Control Mode: " . $viewerControlMode);
writeLog($logFile, "Interrupt Schedule: " . $interruptSchedule);

$fppSchedule = getFppSchedule();
$fppSchedule = getScheduleForCurrentDay($fppSchedule);
$fppScheduleStartTime = $fppSchedule->startTime;
$fppScheduleEndTime = $fppSchedule->endTime;
writeLog($logFile, "Today is " . date("l"));
writeLog($logFile, "Schedule Start Time: " . $fppScheduleStartTime);
writeLog($logFile, "Schedule End Time: " . $fppScheduleEndTime);

while(true) {
  preSchedulePurge($fppScheduleStartTime, $remoteToken, $logFile);

  $currentlyPlaying = "";
  $fppStatus = getFppStatus();
  $currentlyPlaying = $fppStatus->current_sequence;
  $currentlyPlaying = pathinfo($currentlyPlaying, PATHINFO_FILENAME);
  $statusName = $fppStatus->status_name;

  if($currentlyPlaying != $currentlyPlayingInRF) {
    updateWhatsPlaying($currentlyPlaying, $remoteToken);
    writeLog($logFile, "Updated current playing sequence to " . $currentlyPlaying);
    $currentlyPlayingInRF = $currentlyPlaying;
  }

  backupScheduleShutdown($fppScheduleEndTime, $statusName, $logFile);

  if($statusName != "idle" && !isScheduleDone($fppScheduleEndTime)) {
    writeLog($logFile, "Show started");
    //Do not interrupt schedule
    if($interruptSchedule != 1) {
      $fppStatus = getFppStatus();
      $secondsRemaining = intVal($fppStatus->seconds_remaining);
      if($secondsRemaining < 1) {
        writeLog($logFile, "Fetching next sequence");
        if($viewerControlMode == "voting") {
          $highestVotedSequence = highestVotedSequence($remoteToken);
          $winningSequence = $highestVotedSequence->winningPlaylist;
          if($winningSequence != null) {
            $index = getSequenceIndex($remotePlaylistSequences, $winningSequence);
            if($index != 0) {
              insertPlaylistAfterCurrent($remotePlaylistEncoded, $index);
              writeLog($logFile, "Queuing winning sequence " . $winningSequence);
              sleep(5);
            }
          }
        }else {
          $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
          $nextSequence = $nextPlaylistInQueue->nextPlaylist;
          if($nextSequence != null) {
            $index = getSequenceIndex($remotePlaylistSequences, $nextSequence);
            if($index != 0) {
              insertPlaylistAfterCurrent($remotePlaylistEncoded, $index);
              updatePlaylistQueue($remoteToken);
              writeLog($logFile, "Queuing requested sequence " . $nextSequence);
              sleep(5);
            }
          }
        }
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
            writeLog($logFile, "Playing winning sequence " . $winningSequence);
            updateWhatsPlaying($winningSequence, $remoteToken);
            writeLog($logFile, "Updated current playing sequence to " . $winningSequence);
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
            writeLog($logFile, "Playing requested sequence " . $nextSequence);
            updateWhatsPlaying($nextSequence, $remoteToken);
            writeLog($logFile, "Updated current playing sequence to " . $nextSequence);
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
  $currentTime = date("H:i");
  $fppScheduleStartTime = strtotime($fppScheduleStartTime);
  $fppScheduleStartTime = $fppScheduleStartTime - 60;
  $fppScheduleStartTime = date("H:i", $fppScheduleStartTime);
  if($currentTime == $fppScheduleStartTime) {
    writeLog($logFile, "Purging queue and votes");
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
    writeLog($logFile, "Purged");
    sleep(60);
  }
}

function isScheduleDone($fppScheduleEndTime) {
  $currentTime = date("H:i");
  $fppScheduleEndTime = strtotime($fppScheduleEndTime);
  $fppScheduleEndTime = date("H:i", $fppScheduleEndTime);
  if($currentTime >= $fppScheduleEndTime) {
    return true;
  }
  return false;
}

function backupScheduleShutdown($fppScheduleEndTime, $statusName, $logFile) {
  if(isScheduleDone($fppScheduleEndTime) && $statusName != "stopping gracefully" && $statusName != "idle") {
    writeLog($logFile, "Executing backup schedule shutdown");
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

// <option value='7'>Everyday</option>
// <option value='0'>Sunday</option>
// <option value='1'>Monday</option>
// <option value='2'>Tuesday</option>
// <option value='3'>Wednesday</option>
// <option value='4'>Thursday</option>
// <option value='5'>Friday</option>
// <option value='6'>Saturday</option>
// <option value='8'>Mon-Fri</option>
// <option value='9'>Sat/Sun</option>
// <option value='10'>Mon/Wed/Fri</option>
// <option value='11'>Tues/Thurs</option>
// <option value='12'>Sun-Thurs</option>
// <option value='13'>Fri/Sat</option>
// <option value='14'>Odd</option>
// <option value='15'>Even</option>
function getScheduleForCurrentDay($fppSchedule) {
  $dayOfWeek = date("l");
  foreach ($fppSchedule as $schedule) {
    if($schedule->enabled == 1) {
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

function writeLog($logFile, $message) {
  fwrite($logFile, date("Y-m-d H:i:s") . ": " . $message . "\n");
}
?>