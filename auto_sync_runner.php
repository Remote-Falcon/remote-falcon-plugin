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
