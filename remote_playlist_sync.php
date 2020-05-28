<?php
do {
	$pluginPath = "/home/fpp/media/plugins/remote-falcon";
	$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";
	$playlistsPath = "/home/fpp/media/playlists";

	if(file_exists("$pluginPath/remote_token.txt")) {
		$remoteToken = trim(file_get_contents("$pluginPath/remote_token.txt"));
		$playlists = array();
		if ($handle = opendir($playlistsPath)) {
			while (false !== ($entry = readdir($handle))) {
				if ($entry != "." && $entry != "..") {
					$playlistDetails = file_get_contents("$playlistsPath/$entry");
					$playlistDetails = json_decode($playlistDetails);
					$playlist = null;
					$playlist->playlistName = $playlistDetails->name;
					$playlist->playlistDuration = $playlistDetails->playlistInfo->total_duration;
					array_push($playlists, $playlist);
				}
			}
			closedir($handle);
			
			$url = "https://remotefalcon.com/remotefalcon/api/syncPlaylists";
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
			if($response === true) {
				echo "Successfully sent debug report";
			}else {
				echo "Error sending debug report";
			}
		}
	}
	//Sit tight for 4 hours
	sleep(14400);
} while(true);
?>