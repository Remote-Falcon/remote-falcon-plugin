<?php
// Server-side connectivity probe for the "Test Connectivity" UI button.
//
// Invoked same-origin via:
//   plugin.php?plugin=remote-falcon&page=health_check.php&nopage=1
//
// The probe runs the /q/health GET from the FPP server itself — the same
// network path the listener daemon uses — instead of from the browser. A
// browser-side request is subject to FPP's Apache Content-Security-Policy,
// whose connect-src allowlist only contains https://remotefalcon.com, so a
// self-hosted plugins-api URL is blocked and the test reports a false failure
// even though the listener can reach the API. Running it server-side sidesteps
// CSP entirely. See remote-falcon-issue-tracker#157.
//
// Must be requested with &nopage=1 so FPP's plugin.php emits no HTML/JS before
// this page (nopage mode loads config.php only, which populates $settings, and
// skips common.php). We therefore rely on $settings and our own libs, and emit
// nothing before the JSON body.

require_once __DIR__ . '/lib/listener_http.php';
require_once __DIR__ . '/commands/_lib.php';

header('Content-Type: application/json');

$cfg = rf_load_settings();
if ($cfg === null) {
    echo json_encode(['ok' => false, 'error' => 'settings_unavailable']);
    exit;
}

$base = rtrim($cfg['pluginsApiPath'], '/');
if ($base === '') {
    echo json_encode(['ok' => false, 'error' => 'no_api_path']);
    exit;
}

$start = microtime(true);
$body = rf_http_request('GET', $base . '/q/health', ['remotetoken' => $cfg['remoteToken']], null, 5);
$latencyMs = (int) round((microtime(true) - $start) * 1000);

if ($body === null) {
    echo json_encode(['ok' => false, 'error' => 'unreachable', 'latencyMs' => $latencyMs]);
    exit;
}

$data = rf_http_decode_json($body);
$status = (is_object($data) && isset($data->status)) ? $data->status : null;
$ok = ($status !== null && strtoupper((string) $status) === 'UP');

echo json_encode(['ok' => $ok, 'status' => $status, 'latencyMs' => $latencyMs]);
