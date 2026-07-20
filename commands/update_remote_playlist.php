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

// Dispatch the sync out-of-process and return immediately. This callback
// runs synchronously inside fppd's command path (triggered via /api/command,
// which itself occupies a php-fpm worker); doing the sync inline means HTTP
// round-trips back into FPP's own web stack (the playlist GET) from inside
// that chain — under pool pressure the GET times out and the command dies
// silently while the FPP UI reports "complete". The runner (same worker the
// auto-sync watcher uses) syncs, then persists remotePlaylist + the restart
// flags via --set-remote, and logs its outcome to the listener log.
$runner = dirname(__DIR__) . '/auto_sync_runner.php';
exec('php ' . escapeshellarg($runner) . ' ' . escapeshellarg($remotePlaylist)
    . ' --set-remote > /dev/null 2>&1 &');
echo "Update dispatched for '" . $remotePlaylist . "' — outcome logs to the listener log\n";
?>
