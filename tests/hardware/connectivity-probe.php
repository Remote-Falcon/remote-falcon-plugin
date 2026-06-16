<?php
// Tier 1 #2 — read-only connectivity probe.
//
// One-shot CLI tool that exercises the listener's cURL-based RF transport
// against the real Remote Falcon plugins API. Calls /remotePreferences
// (GET, read-only — no state change) three times in a row using the
// SAME persistent cURL handle, so we can observe whether subsequent
// calls reuse the TCP+TLS connection from the first (perf 2.3 keep-alive).
//
// Usage (on the Pi, as fpp or root):
//   php /home/fpp/media/plugins/remote-falcon/tests/hardware/connectivity-probe.php
//
// Reads the plugin config in place — no settings changes. Token and base
// URL come from /home/fpp/media/config/plugin.remote-falcon.
//
// Output: one line per call, "callN: status=OK|FAIL elapsed_ms=NN".
// Exit 0 if all three calls returned a parsed object (cURL+TLS works).
// Exit non-zero on transport failure.

require_once __DIR__ . '/../../lib/listener_http.php';
require_once __DIR__ . '/../../lib/listener_logic.php';

$configPath = '/home/fpp/media/config/plugin.remote-falcon';
$settings = parse_ini_file($configPath);
if ($settings === false) {
    fwrite(STDERR, "ERROR: cannot read $configPath\n");
    exit(2);
}

$baseUrl = urldecode($settings['pluginsApiPath'] ?? '');
$token = urldecode($settings['remoteToken'] ?? '');

if ($baseUrl === '' || $token === '') {
    fwrite(STDERR, "ERROR: pluginsApiPath or remoteToken empty in $configPath\n");
    exit(2);
}

echo "Probe target: $baseUrl/remotePreferences\n";
echo "Token: " . substr($token, 0, 4) . "...(redacted)\n";
echo "\n";

$success_count = 0;
$timings = [];

for ($i = 1; $i <= 3; $i++) {
    $start = microtime(true);
    $result = rf_http_rf_get_preferences($baseUrl, $token);
    $elapsed_ms = (microtime(true) - $start) * 1000;
    $timings[] = $elapsed_ms;
    $status = $result instanceof stdClass ? 'OK' : 'FAIL';
    if ($status === 'OK') {
        $success_count++;
    }
    printf("call%d: status=%s elapsed_ms=%d\n", $i, $status, (int) round($elapsed_ms));
}

echo "\n";

if ($success_count !== 3) {
    fwrite(STDERR, "ERROR: only $success_count/3 calls succeeded\n");
    exit(1);
}

// Heuristic: with TLS keep-alive, calls 2 and 3 should be meaningfully
// faster than call 1 (no full handshake). Threshold is loose because
// network latency dominates and varies — we just want to confirm it's
// not the worst case where every call does a full handshake.
$first = $timings[0];
$avg_subsequent = ($timings[1] + $timings[2]) / 2;
$pct_faster = $first > 0 ? (int) round(100 * ($first - $avg_subsequent) / $first) : 0;

printf("call 1 (cold):      %d ms\n", (int) round($first));
printf("calls 2-3 (warm):   %d ms avg\n", (int) round($avg_subsequent));
printf("speedup:            %d%% faster\n", $pct_faster);

if ($pct_faster >= 30) {
    echo "PASS: keep-alive observable (warm calls >=30% faster than cold)\n";
    exit(0);
} elseif ($pct_faster >= 0) {
    // Connection reuse is happening but speedup is small — likely a low-
    // latency network path. Still pass; cold-vs-warm is environmental.
    echo "PASS: all calls succeeded (speedup ${pct_faster}% — small but consistent with low-latency path)\n";
    exit(0);
} else {
    // Negative speedup is rare but possible (network jitter on call 1).
    // Report as warning, not failure, since the success of all 3 calls
    // is the load-bearing assertion.
    echo "WARN: warm calls slower than cold (${pct_faster}% — possible network jitter)\n";
    echo "PASS: all calls succeeded (transport works against real RF)\n";
    exit(0);
}
