<?php
$pluginPath = "/home/fpp/media/plugins/remote-falcon";
$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";
$playlistsPath = "/home/fpp/media/playlists";

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
	echo json_encode($playlists);
}
?>