<?php
// Dropdown feeder for the "Remote Falcon - Set Active Viewer Page" FPP
// command (PRD-016 / issue #68).
//
// Invoked same-origin by FPP's command-args UI via contentListUrl:
//   plugin.php?plugin=remote-falcon&page=viewer_pages_list.php&nopage=1
//
// Fetches the show's viewer pages from <pluginsApiPath>/viewerPages
// (server-side, so it's clear of FPP's Apache CSP for self-hosted API URLs —
// see remote-falcon-issue-tracker#157) and emits a plain JSON array of page
// names. FPP's contentListUrl handler (www/js/fpp.js) expects either a plain
// array (value == label) or a key/value object map — a plain array matches
// what /api/playlists returns for the Update Remote Playlist command.
//
// On any failure emit [] — FPP renders an empty dropdown rather than erroring.
//
// Must be requested with &nopage=1 (see health_check.php for why).

require_once __DIR__ . '/lib/listener_http.php';
require_once __DIR__ . '/commands/_lib.php';

header('Content-Type: application/json');

$cfg = rf_load_settings();
if ($cfg === null || strlen($cfg['remoteToken']) <= 1 || rtrim($cfg['pluginsApiPath'], '/') === '') {
    echo json_encode([]);
    exit;
}

$response = rf_http_request(
    'GET',
    rtrim($cfg['pluginsApiPath'], '/') . '/viewerPages',
    ['Accept' => 'application/json', 'remotetoken' => $cfg['remoteToken']],
    null,
    10
);

$decoded = $response !== null ? json_decode($response, true) : null;
if (!is_array($decoded) || !isset($decoded['pages']) || !is_array($decoded['pages'])) {
    echo json_encode([]);
    exit;
}

$names = [];
foreach ($decoded['pages'] as $page) {
    if (is_array($page) && isset($page['name']) && is_string($page['name']) && $page['name'] !== '') {
        $names[] = $page['name'];
    }
}

echo json_encode($names);
