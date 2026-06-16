<?php
// Server-side proxy for the plugin -> Remote Falcon version report.
//
// Invoked same-origin via:
//   plugin.php?plugin=remote-falcon&page=report_version.php&nopage=1
//
// The browser previously POSTed /pluginVersion directly to the RF API. That
// request is blocked by FPP's Apache Content-Security-Policy for self-hosted
// (non-remotefalcon.com) API URLs, so a self-hosted dashboard never recorded
// the plugin/FPP version. Forwarding the POST from the FPP server is the same
// path the listener uses and is not subject to CSP. See
// remote-falcon-issue-tracker#157.
//
// Must be requested with &nopage=1 (see health_check.php for why). The browser
// supplies the {pluginVersion, fppVersion} payload it already gathers from the
// same-origin FPP API; we forward it verbatim with the show's remote token.

require_once __DIR__ . '/lib/listener_http.php';
require_once __DIR__ . '/commands/_lib.php';

header('Content-Type: application/json');

$cfg = rf_load_settings();
if ($cfg === null || strlen($cfg['remoteToken']) <= 1) {
    echo json_encode(['ok' => false, 'error' => 'no_token']);
    exit;
}

$base = rtrim($cfg['pluginsApiPath'], '/');
if ($base === '') {
    echo json_encode(['ok' => false, 'error' => 'no_api_path']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$payload = json_encode([
    'pluginVersion' => $input['pluginVersion'] ?? '',
    'fppVersion' => $input['fppVersion'] ?? '',
]);

$response = rf_http_request(
    'POST',
    $base . '/pluginVersion',
    ['Content-Type' => 'application/json', 'remotetoken' => $cfg['remoteToken']],
    $payload,
    10
);

echo json_encode(['ok' => $response !== null]);
