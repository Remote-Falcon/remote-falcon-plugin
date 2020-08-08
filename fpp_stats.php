<?php
$pluginPath = "/home/fpp/media/plugins/remote-falcon";
$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";

$remoteToken = trim(file_get_contents("$pluginPath/remote_token.txt"));

while(true) {
  echo "Sending FPP Stats to Remote Falcon\n";
  $currentlyPlaying = "";
  $url = "http://127.0.0.1/api/fppd/status";
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  $response = json_decode( $result );
  $fppdStatus = $response->fppd;
  $fppStatus = $response->status_name;
  $sensors = $response->sensors;
  $cpuTemp = "";
  foreach ($sensors as $sensor) {
    if($sensor->valueType == "Temperature") {
      $cpuTemp = $sensor->formatted;
    }
  }
  $volume = $response->volume;
  $currentPlayingSequence = $response->current_sequence;
  $currentPlayingSequence = pathinfo($currentPlayingSequence, PATHINFO_FILENAME);
  $currentPlayingPlaylist = $response->current_playlist->playlist;
  $scheduledPlaylist = $response->scheduler->currentPlaylist->playlistName;
  $scheduledStartTime = $response->scheduler->currentPlaylist->scheduledStartTimeStr;
  $scheduledEndTime = $response->scheduler->currentPlaylist->scheduledEndTimeStr;

  $url = "https://remotefalcon.com/remotefalcon/api/fppStats";
  $data = array(
    'fppdStatus' => trim($fppdStatus),
    'fppStatus' => trim($fppStatus),
    'cpuTemp' => trim($cpuTemp),
    'volume' => $volume,
    'currentPlayingSequence' => trim($currentPlayingSequence),
    'currentPlayingPlaylist' => trim($currentPlayingPlaylist),
    'scheduledPlaylist' => trim($scheduledPlaylist),
    'scheduledStartTime' => trim($scheduledStartTime),
    'scheduledEndTime' => trim($scheduledEndTime)
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
  sleep (10);
}
?>