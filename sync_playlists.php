<?php
// Server-side proxy for the "Sync with RF" button.
//
// Invoked same-origin via:
//   plugin.php?plugin=remote-falcon&page=sync_playlists.php&nopage=1
//
// Forwards the browser-built {playlists:[...]} payload to
// <pluginsApiPath>/syncPlaylists from the FPP server, so the POST is not
// blocked by FPP's Apache Content-Security-Policy for self-hosted
// (non-remotefalcon.com) API URLs. See remote-falcon-issue-tracker#157.
//
// This is a transparent pass-through: the browser remains the source of truth
// for the payload (playlist types, media title/artist, album art, ordering).
// The headless "Remote Falcon - Update Remote Playlist" FPP command builds a
// thinner payload and is intentionally NOT touched here — the two sync paths
// have drifted and reconciling them is tracked separately.
//
// Must be requested with &nopage=1 (see health_check.php for why).

require_once __DIR__ . '/lib/listener_http.php';
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
if (!is_array($input) || !isset($input['playlists']) || !is_array($input['playlists'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$payload = json_encode(['playlists' => $input['playlists']]);

$response = rf_http_request(
    'POST',
    $base . '/syncPlaylists',
    ['Content-Type' => 'application/json', 'remotetoken' => $cfg['remoteToken']],
    $payload,
    30
);

if ($response === null) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'sync_failed']);
    exit;
}

echo json_encode(['ok' => true]);
