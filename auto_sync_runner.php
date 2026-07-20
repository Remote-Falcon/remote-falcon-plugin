#!/usr/bin/env php
<?php
// Background worker for Auto Sync Playlist (#13).
//
// Performs one playlist sync out-of-process so the listener's ~1Hz tick
// never blocks on the sync's HTTP round-trips (playlist GET + per-item
// media-metadata GETs + the RF POST). An in-tick sync could stall FPP
// status polling, viewer request handling, and the 30s heartbeat for the
// full sync duration — 2026-07-16 release review, finding 1.
//
// Dispatched detached by remote_falcon_listener.php:
//   php auto_sync_runner.php <playlistName> > /dev/null 2>&1 &
// Logs directly to the listener log via logEntry(). Serialized by a
// non-blocking flock: if a previous sync is still running, this pass skips
// (the watcher fires again on the next playlist change).

$skipJSsettings = true;
// Buffer + suppress: common.php emits HTML and reads web-only $_SERVER keys
// (see the identical dance at the top of remote_falcon_listener.php).
ob_start();
@include_once "/opt/fpp/www/common.php";
ob_end_clean();

require_once __DIR__ . '/lib/listener_log.php';
require_once __DIR__ . '/lib/listener_http.php';
require_once __DIR__ . '/lib/sync_builder.php';
require_once __DIR__ . '/commands/_lib.php';

$pluginName = basename(__DIR__);
$logFile = $settings['logDirectory'] . "/" . $pluginName . "-listener.log";

$playlistName = isset($argv[1]) ? $argv[1] : '';
if ($playlistName === '') {
    logEntry("ERROR - Auto Sync runner: no playlist name given");
    exit(1);
}
// --set-remote: after a successful sync, persist this playlist as the synced
// remotePlaylist and flag a listener soft-restart. Used by the "Update Remote
// Playlist" FPP command, which dispatches here instead of syncing inline: a
// synchronous fppd command callback doing HTTP round-trips back into FPP's
// own web stack (the playlist GET via /api) can exhaust the php-fpm pool and
// hang or die silently — same class of stall as the listener tick fix
// (2026-07-16 release review, finding 1).
$setRemote = in_array('--set-remote', $argv, true);

$lockPath = sys_get_temp_dir() . '/remote-falcon-auto-sync.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    logEntry("Auto Sync: previous sync still running; skipping this pass for '" . $playlistName . "'");
    exit(0);
}

$cfg = rf_load_settings();
if ($cfg === null || strlen($cfg['remoteToken']) <= 1) {
    logEntry("ERROR - Auto Sync runner: remote token missing");
    exit(1);
}

$result = rf_sync_playlist_to_rf($playlistName, $cfg);
if ($result['ok']) {
    logEntry("Auto Sync: synced " . $result['count'] . " items for '" . $playlistName . "'");
} else {
    logEntry("ERROR - Auto Sync failed for '" . $playlistName . "': " . $result['error']);
    exit(1);
}

if ($setRemote) {
    WriteSettingToFile("remotePlaylist", urlencode($playlistName), $pluginName);
    $listenerEnabled = isset($cfg['raw']['remoteFalconListenerEnabled'])
        ? urldecode($cfg['raw']['remoteFalconListenerEnabled']) : '';
    if ($listenerEnabled == "true") {
        WriteSettingToFile("remoteFalconListenerEnabled", urlencode("false"), $pluginName);
        WriteSettingToFile("remoteFalconListenerRestarting", urlencode("true"), $pluginName);
    }
    logEntry("Update Remote Playlist: remotePlaylist set to '" . $playlistName . "'"
        . ($listenerEnabled == "true" ? "; listener restart requested" : ""));
}
