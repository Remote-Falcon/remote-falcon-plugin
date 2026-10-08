<?php
// Listener status for the plugin page's "Listener Status" panel.
//
// Invoked same-origin via:
//   plugin.php?plugin=remote-falcon&page=status.php&nopage=1
//
// The listener writes remote_falcon_status.json (rf_status_update in
// lib/listener_actions.php) when its mode, request/vote checks, FPP inserts or
// heartbeat change. This page only reads it. "now" is FPP's clock, so the page
// can show "2 min ago" without trusting the browser's clock.
//
// Must be requested with &nopage=1 so FPP's plugin.php emits nothing before
// the JSON body (same pattern as health_check.php).

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$path = __DIR__ . '/remote_falcon_status.json';
$raw = is_file($path) ? @file_get_contents($path) : false;
$status = $raw === false ? null : json_decode($raw, true);

if (!is_array($status)) {
    echo json_encode(['ok' => false, 'error' => 'no_status', 'now' => time()]);
    exit;
}

echo json_encode(['ok' => true, 'now' => time(), 'status' => $status]);
