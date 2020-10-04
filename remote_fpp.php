<?php
$pluginPath = "/home/fpp/media/plugins/remote-falcon";
$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";

echo "Starting Remote Falcon\n";

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

while(true) {
  preSchedulePurge($remoteToken);

  $currentlyPlaying = "";
  $fppStatus = getFppStatus();
  $currentlyPlaying = $fppStatus->current_sequence;
  $currentlyPlaying = pathinfo($currentlyPlaying, PATHINFO_FILENAME);
  $statusName = $fppStatus->status_name;

  if($statusName != "idle") {
    if($currentlyPlaying != $currentlyPlayingInRF) {
      updateWhatsPlaying($currentlyPlaying, $remoteToken);
      echo "Updated current playing sequence to " . $currentlyPlaying . "\n";
      $currentlyPlayingInRF = $currentlyPlaying;
    }
  
    //Do not interrupt schedule
    if($interruptSchedule != 1) {
      $fppStatus = getFppStatus();
      $secondsRemaining = intVal($fppStatus->seconds_remaining);
      if($secondsRemaining < 1) {
        echo "Only 1 second remaining, fetch the next sequence\n";
        if($viewerControlMode == "voting") {
          $highestVotedSequence = highestVotedSequence($remoteToken);
          $winningSequence = $highestVotedSequence->winningPlaylist;
          if($winningSequence != null) {
            $index = getSequenceIndex($remotePlaylistSequences, $winningSequence);
            if($index != 0) {
              insertPlaylistAfterCurrent($remotePlaylistEncoded, $index);
              echo "Queuing winning sequence " . $winningSequence . "\n";
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
              echo "Queuing requested sequence " . $nextSequence . "\n";
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
            echo "Playing winning sequence " . $winningSequence . "\n";
            updateWhatsPlaying($winningSequence, $remoteToken);
            echo "Updated current playing sequence to " . $winningSequence . "\n";
            $currentlyPlayingInRF = $winningSequence;
            sleep(5);
            holdForImmediatePlay();
          }
        }else {
          echo "Waiting 5 seconds for next request\n";
          sleep (5);
        }
      }else {
        $nextPlaylistInQueue = nextPlaylistInQueue($remoteToken);
        $nextSequence = $nextPlaylistInQueue->nextPlaylist;
        if($nextSequence != null) {
          $index = getSequenceIndex($remotePlaylistSequences, $nextSequence);
          if($index != 0) {
            insertPlaylistImmediate($remotePlaylistEncoded, $index);
            updatePlaylistQueue($remoteToken);
            echo "Playing requested sequence " . $nextSequence . "\n";
            updateWhatsPlaying($nextSequence, $remoteToken);
            echo "Updated current playing sequence to " . $nextSequence . "\n";
            $currentlyPlayingInRF = $nextSequence;
            sleep(5);
            holdForImmediatePlay();
          }
        }else {
          echo "Waiting 5 seconds for next request\n";
          sleep (5);
        }
      }
    }
  }
}

function holdForImmediatePlay() {
  $fppStatus = getFppStatus();
  $secondsRemaining = intVal($fppStatus->seconds_remaining);
  while($secondsRemaining > 1) {
    $fppStatus = getFppStatus();
    $secondsRemaining = intVal($fppStatus->seconds_remaining);
  }
}

function sleepAfterImmediateRequest() {
  sleep(3);
  $fppStatus = getFppStatus();
  $secondsRemaining = intVal($fppStatus->seconds_remaining);
  $secondsRemaining = $secondsRemaining == 0 ? 0 : $secondsRemaining - 1;
  echo "Sleeping " . $secondsRemaining . " seconds before fetching next sequence \n";
  sleep($secondsRemaining);
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

function preSchedulePurge($remoteToken) {
  $currentTime = date("H:i");
  $fppSchedule = getFppSchedule();
  $fppScheduleStartTimes = array();
  foreach ($fppSchedule as $schedule) {
    $fppScheduleStartTime = strtotime($schedule->startTime);
    $fppScheduleStartTime = $fppScheduleStartTime - 60;
    $fppScheduleStartTime = date("H:i", $fppScheduleStartTime);
    array_push($fppScheduleStartTimes, $fppScheduleStartTime);
  }
  if(count($fppScheduleStartTimes) > 0 && $currentTime == min($fppScheduleStartTimes)) {
    echo "Purging queue and votes\n";
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
    echo "Purged\n";
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
?>