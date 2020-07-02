<?php
$pluginPath = "/home/fpp/media/plugins/remote-falcon";
$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";

echo "Starting Remote Falcon\n";

$remoteToken = trim(file_get_contents("$pluginPath/remote_token.txt"));
$remotePlaylist = trim(file_get_contents("$pluginPath/remote_playlist.txt"));
$url = "http://localhost/api/playlist/" . $remotePlaylist;
$options = array(
  'http' => array(
    'method'  => 'GET'
    )
);
$context = stream_context_create( $options );
$result = file_get_contents( $url, false, $context );
$response = json_decode( $result );
$remotePlaylistSequences = $response->mainPlaylist;

$currentlyPlayingInRF = "";
$viewerControlMode = "";
$url = "https://remotefalcon.com/remotefalcon/api/viewerControlMode";
$options = array(
  'http' => array(
    'method'  => 'GET',
    'header'=>  "remotetoken: $remoteToken\r\n"
    )
);
$context = stream_context_create( $options );
$result = file_get_contents( $url, false, $context );
$response = json_decode( $result );
$viewerControlMode = $response->viewerControlMode;

while(true) {
  $currentlyPlaying = "";
  $url = "http://localhost/api/fppd/status";
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  $response = json_decode( $result );
  $currentlyPlaying = $response->current_sequence;
  $currentlyPlaying = pathinfo($currentlyPlaying, PATHINFO_FILENAME);

  if($currentlyPlaying != $currentlyPlayingInRF) {
    echo "Updating current playing sequence to " . $currentlyPlaying . "\n";
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
    $currentlyPlayingInRF = $currentlyPlaying;
  }

  $url = "http://localhost/api/fppd/status";
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  $response = json_decode( $result );
  $fppSchedulePlaying = $response->status;
  $fppSchedulePlaying = $fppSchedulePlaying == 1 ? true : false;
  if($fppSchedulePlaying) {
    if($viewerControlMode == "voting") {
      $url = "https://remotefalcon.com/remotefalcon/api/highestVotedPlaylist";
      $options = array(
        'http' => array(
          'method'  => 'GET',
          'header'=>  "remotetoken: $remoteToken\r\n"
          )
      );
      $context = stream_context_create( $options );
      $result = file_get_contents( $url, false, $context );
      $response = json_decode( $result );
      $winningSequence = $response->winningPlaylist;
      if($winningSequence != null) {
        $index = 1;
        $validSequence = false;
        foreach ($remotePlaylistSequences as $sequence) {
          if(property_exists($sequence, 'sequenceName')) {
            $sequenceName = $sequence->sequenceName;
            $sequenceName = pathinfo($sequenceName, PATHINFO_FILENAME);
            if($sequenceName == $winningSequence) {
              $validSequence = true;
              break;
            }
          }
          $index++;
        }
        if($validSequence) {
          echo "Starting winning sequence " . $winningSequence . " at index " . $index . "\n";
          $url = "http://localhost/api/command/Insert%20Playlist%20Immediate/" . $remotePlaylist . "/" . $index . "/" . $index;
          $options = array(
            'http' => array(
              'method'  => 'GET'
              )
          );
          $context = stream_context_create( $options );
          $result = file_get_contents( $url, false, $context );
          $url = "http://localhost/api/playlists/stopgracefully";
          $options = array(
            'http' => array(
              'method'  => 'GET'
              )
          );
          $context = stream_context_create( $options );
          $result = file_get_contents( $url, false, $context );
        }
      }
    }else {
      $url = "https://remotefalcon.com/remotefalcon/api/nextPlaylistInQueue";
      $options = array(
        'http' => array(
          'method'  => 'GET',
          'header'=>  "remotetoken: $remoteToken\r\n"
          )
      );
      $context = stream_context_create( $options );
      $result = file_get_contents( $url, false, $context );
      $response = json_decode( $result );
      $requestedSequence = $response->nextPlaylist;
      if($requestedSequence != null) {
        $index = 1;
        $validSequence = false;
        foreach ($remotePlaylistSequences as $sequence) {
          if(property_exists($sequence, 'sequenceName')) {
            $sequenceName = $sequence->sequenceName;
            $sequenceName = pathinfo($sequenceName, PATHINFO_FILENAME);
            if($sequenceName == $requestedSequence) {
              $validSequence = true;
              break;
            }
          }
          $index++;
        }
        if($validSequence) {
          echo "Starting requested sequence " . $requestedSequence . " at index " . $index . "\n";
          $url = "http://localhost/api/command/Insert%20Playlist%20Immediate/" . $remotePlaylist . "/" . $index . "/" . $index;
          $options = array(
            'http' => array(
              'method'  => 'GET'
              )
          );
          $context = stream_context_create( $options );
          $result = file_get_contents( $url, false, $context );
          $url = "http://localhost/api/playlists/stopgracefully";
          $options = array(
            'http' => array(
              'method'  => 'GET'
              )
          );
          $context = stream_context_create( $options );
          $result = file_get_contents( $url, false, $context );
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
      }
    }
  }
  if($fppSchedulePlaying) {
    echo "Waiting for next request\n";
    sleep (5);
  }
}
?>