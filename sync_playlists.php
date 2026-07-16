<?php
// Server-side sync for the "Sync with RF" button.
//
// Invoked same-origin via:
//   plugin.php?plugin=remote-falcon&page=sync_playlists.php&nopage=1
//
// The browser sends {playlistName, syncMetadata} and this page builds the
// full rich payload via the shared builder (lib/sync_builder.php) and POSTs
// it to <pluginsApiPath>/syncPlaylists — the same builder the headless
// "Remote Falcon - Update Remote Playlist" command uses, so the two sync
// paths can no longer drift (issue #158). Running server-side also keeps the
// POST clear of FPP's Apache Content-Security-Policy for self-hosted API
// URLs (issue #157).
//
// Back-compat: a legacy {playlists:[...]} body (browser-built payload from a
// cached pre-#158 remote_falcon_ui.js) is still forwarded verbatim.
//
// Must be requested with &nopage=1 (see health_check.php for why).

require_once __DIR__ . '/lib/listener_http.php';
require_once __DIR__ . '/lib/sync_builder.php';
require_once __DIR__ . '/commands/_lib.php';

header('Content-Type: application/json');

$cfg = rf_load_settings();
if ($cfg === null || strlen($cfg['remoteToken']) <= 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no_token']);
    exit;
}

$base = rtrim($cfg['pluginsApiPath'], '/');
if ($base === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no_api_path']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

// Legacy pass-through: browser-built payload from a cached pre-#158 UI.
// SUNSET: remote_falcon_ui.html now cache-busts the script by filemtime, so
// this shape can only arrive from a tab loaded before the upgrade. Remove
// this branch in the first release after 2026.07 (return invalid_payload;
// the UI's generic error + a page reload recovers the user).
if (isset($input['playlists']) && is_array($input['playlists'])) {
    $response = rf_http_request(
        'POST',
        $base . '/syncPlaylists',
        ['Content-Type' => 'application/json', 'remotetoken' => $cfg['remoteToken']],
        json_encode(['playlists' => $input['playlists']]),
        30
    );
    if ($response === null) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'sync_failed']);
        exit;
    }
    echo json_encode(['ok' => true, 'count' => count($input['playlists'])]);
    exit;
}

if (!isset($input['playlistName']) || !is_string($input['playlistName']) || $input['playlistName'] === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$opts = [];
if (isset($input['syncMetadata'])) {
    $opts['syncMetadata'] = (bool) $input['syncMetadata'];
}

$result = rf_sync_playlist_to_rf($input['playlistName'], $cfg, $opts);

if (!$result['ok']) {
    // playlist_fetch_failed / empty_playlist / too_many_items are caller
    // errors; sync_failed means the RF API didn't answer.
    http_response_code($result['error'] === 'sync_failed' ? 502 : 400);
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

echo json_encode(['ok' => true, 'count' => $result['count']]);
