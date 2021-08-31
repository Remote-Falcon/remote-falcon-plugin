<?php
include_once "/opt/fpp/www/common.php";
include_once "/home/fpp/media/plugins/remote-falcon/baseurl.php";
$pluginName = "remote-falcon";
$baseUrl = getBaseUrl();
$pluginConfigFile = $settings['configDirectory'] . "/plugin.remote-falcon";
$pluginSettings = parse_ini_file($pluginConfigFile);

/**
 * IF USING THIS SCRIPT AND NOT THE COMMAND, JUST CHANGE THE remotePlaylist VARIABLE FROM $argv[1] TO THE PLAYLIST YOU WANT TO SYNC
 */
$remotePlaylist = $argv[1];

$remoteToken = urldecode($pluginSettings['remoteToken']);
if(strlen($remoteToken)>1) {
  echo "Updating\n";
	$playlists = array();
  $remotePlaylistEncoded = rawurlencode($remotePlaylist);
  $url = "http://127.0.0.1/api/playlist/${remotePlaylistEncoded}";
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create( $options );
  $result = file_get_contents( $url, false, $context );
  $response = json_decode( $result, true );
  $mainPlaylist = $response['mainPlaylist'];
  $index = 1;
  foreach($mainPlaylist as $item) {
    if($item['type'] == 'both' || $item['type'] == 'sequence') {
      $playlist = null;
      $playlist->playlistName = pathinfo($item['sequenceName'], PATHINFO_FILENAME);
      $playlist->playlistDuration = $item['duration'];
      $playlist->playlistIndex = $index;
      array_push($playlists, $playlist);
    }else if($item['type'] == 'media') {
      $playlist = null;
      $playlist->playlistName = pathinfo($item['mediaName'], PATHINFO_FILENAME);
      $playlist->playlistDuration = $item['duration'];
      $playlist->playlistIndex = $index;
      array_push($playlists, $playlist);
    }
    $index++;
  }
  $url = $baseUrl . "/remotefalcon/api/syncPlaylists";
  $data = array(
    'playlists' => $playlists
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
  $response = json_decode( $result );
  if($response) {
    WriteSettingToFile("remotePlaylist",$remotePlaylist,$pluginName);
    if($remoteFppEnabled == 1) {
      WriteSettingToFile("remote_fpp_enabled",urlencode("false"),$pluginName);
      WriteSettingToFile("remote_fpp_restarting",urlencode("true"),$pluginName);
    }
  }
  echo "Done!";
}
?>