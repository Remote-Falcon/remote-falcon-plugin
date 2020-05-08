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
					$playlistItems = array();
					foreach ($playlistDetails->mainPlaylist as &$mainPlaylist) {
						$playlistItem = null;
						$playlistItem->playlistItemType = $mainPlaylist->type == null || $mainPlaylist->type == "null" ? null : $mainPlaylist->type;
						$playlistItem->playlistItemEnabled = $mainPlaylist->enabled == null || $mainPlaylist->enabled == "null" ? null : $mainPlaylist->enabled;
						$playlistItem->playlistItemSequenceName = $mainPlaylist->sequenceName == null || $mainPlaylist->sequenceName == "null" ? null : $mainPlaylist->sequenceName;
						$playlistItem->playlistItemMediaName = $mainPlaylist->mediaName == null || $mainPlaylist->mediaName == "null" ? null : $mainPlaylist->mediaName;
						$playlistItem->playlistItemDuration = $mainPlaylist->duration == null || $mainPlaylist->duration == "null" ? null : $mainPlaylist->duration;
						array_push($playlistItems, $playlistItem);
					}
					$playlist = null;
					$playlist->playlistName = $playlistDetails->name;
					$playlist->playlistDuration = $playlistDetails->playlistInfo->total_duration;
					$playlist->playlistItems = $playlistItems;
					array_push($playlists, $playlist);
				}
			}
			closedir($handle);
			
			$url = "https://remotefalcon.com/services/rmrghbsEvMhSH8LKuJydVn23pvsFKX/remoteFalcon/syncPlaylists.php";
			$data = array(
				'remoteToken' => $remoteToken,
				'playlists' => $playlists
			);
			$options = array(
				'http' => array(
					'method'  => 'POST',
					'content' => json_encode( $data ),
					'header'=>  "Content-Type: application/json\r\n" .
											"Accept: application/json\r\n"
					)
			);
			$context = stream_context_create( $options );
			$result = file_get_contents( $url, false, $context );
			$response = json_decode( $result );
		}
	}
	//Sit tight for 4 hours
	sleep(14400);
} while(true);
?>