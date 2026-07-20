#!/usr/bin/env php
<?php
// FPP command: "Remote Falcon - Set Active Viewer Page" (PRD-016 / issue #68)
//
// Makes the named viewer page the live one in Remote Falcon. Schedulable via
// FPP Scheduler (e.g. a different page on different days) or usable from any
// FPP command preset/event. The page-name arg is a dropdown fed by
// viewer_pages_list.php at authoring time; the saved schedule stores the name
// string, so a page renamed in RF needs its schedule entry re-picked.
//
// A no-match fails loudly: the RF API returns the list of valid page names
// and we echo it, so the mistake is visible in the FPP command/schedule log.

$skipJSsettings = true;
include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . "/_lib.php";
require_once __DIR__ . "/../lib/listener_http.php";

$pageName = isset($argv[1]) ? trim($argv[1]) : '';
if ($pageName === '') {
    echo "No viewer page name given\n";
    exit(1);
}

$cfg = rf_load_settings();
if ($cfg === null || strlen($cfg['remoteToken']) <= 1) {
    echo "Remote Token Missing\n";
    exit(1);
}

$result = rf_http_request_with_status(
    'POST',
    rtrim($cfg['pluginsApiPath'], '/') . '/setActiveViewerPage',
    ['Content-Type' => 'application/json', 'remotetoken' => $cfg['remoteToken']],
    json_encode(['pageName' => $pageName]),
    15
);

if ($result['body'] === null) {
    echo "Set Active Viewer Page failed: no response from Remote Falcon API\n";
    exit(1);
}

$decoded = json_decode($result['body'], true);
$message = is_array($decoded) && isset($decoded['message']) ? $decoded['message'] : '';
// A 2xx from this endpoint is definitionally success — the API's only
// non-throwing path returns 200. Don't couple to the message wording
// (2026-07-16 release review): a reworded success must not log as failure.
if ($result['status'] >= 200 && $result['status'] < 300) {
    echo "Active viewer page set to '" . $pageName . "'\n";
} else {
    // 4xx bodies (unknown page name, etc.) include the valid names, so the
    // typo is debuggable straight from the FPP command/schedule log.
    echo "Set Active Viewer Page failed: " . ($message !== '' ? $message : $result['body']) . "\n";
    exit(1);
}
?>
