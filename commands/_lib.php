<?php
// Shared helpers for Remote Falcon command scripts.
// Loads plugin settings and performs HTTP requests to the Remote Falcon plugins API.

function rf_load_settings() {
    global $settings;
    $pluginConfigFile = $settings['configDirectory'] . "/plugin.remote-falcon";
    $pluginSettings = parse_ini_file($pluginConfigFile);
    if ($pluginSettings === false) {
        return null;
    }
    return [
        'remoteToken' => urldecode($pluginSettings['remoteToken'] ?? ''),
        'pluginsApiPath' => urldecode($pluginSettings['pluginsApiPath'] ?? ''),
        'raw' => $pluginSettings,
    ];
}

function rf_request($method, $url, $remoteToken, $jsonBody = null) {
    $headers = "Accept: application/json\r\nremotetoken: $remoteToken\r\n";
    if ($jsonBody !== null) {
        $headers = "Content-Type: application/json; charset=UTF-8\r\n" . $headers;
    }
    $opts = [
        'http' => [
            'method' => $method,
            'timeout' => 10,
            'header' => $headers,
        ],
    ];
    if ($jsonBody !== null) {
        $opts['http']['content'] = json_encode($jsonBody);
    }
    $context = stream_context_create($opts);
    return @file_get_contents($url, false, $context);
}
?>
