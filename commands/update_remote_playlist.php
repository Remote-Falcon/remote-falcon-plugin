#!/usr/bin/env php
<?php
// FPP command: "Remote Falcon - Update Remote Playlist"
//
// Syncs the given FPP playlist to Remote Falcon via the shared builder
// (lib/sync_builder.php), so this headless path sends the exact same rich
// payload as the UI "Sync with RF" button: playlist types, media
// title/artist/album art (when the Auto Sync Metadata setting is on), and
// command items. See issue #158 for the drift this replaced.
//
// On success it saves the playlist as the synced remotePlaylist and restarts
// the listener (the listener caches remotePlaylist at startup).

$skipJSsettings = true;
include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";

require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/../lib/listener_http.php';
require_once __DIR__ . '/../lib/sync_builder.php';

$pluginName = "remote-falcon";
$remotePlaylist = isset($argv[1]) ? $argv[1] : '';

if ($remotePlaylist === '') {
    echo "No playlist name given\n";
    exit(1);
}

$cfg = rf_load_settings();
if ($cfg === null || strlen($cfg['remoteToken']) <= 1) {
    echo "Remote Token Missing\n";
    exit(1);
}

echo "Updating\n";
$result = rf_sync_playlist_to_rf($remotePlaylist, $cfg);

if ($result['ok']) {
    WriteSettingToFile("remotePlaylist", urlencode($remotePlaylist), $pluginName);
    $remoteFalconListenerEnabled = isset($cfg['raw']['remoteFalconListenerEnabled'])
        ? urldecode($cfg['raw']['remoteFalconListenerEnabled']) : '';
    if ($remoteFalconListenerEnabled == "true") {
        WriteSettingToFile("remoteFalconListenerEnabled", urlencode("false"), $pluginName);
        WriteSettingToFile("remoteFalconListenerRestarting", urlencode("true"), $pluginName);
    }
    echo "Done! Synced " . $result['count'] . " items.\n";
} else {
    echo "Sync failed: " . $result['error'] . "\n";
    exit(1);
}
?>
