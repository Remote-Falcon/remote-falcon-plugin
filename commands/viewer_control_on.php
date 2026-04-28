#!/usr/bin/env php
<?php
$skipJSsettings = true;
include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . "/_lib.php";

$cfg = rf_load_settings();
if ($cfg === null || strlen($cfg['remoteToken']) <= 1) {
    exit(0);
}

rf_request('POST', $cfg['pluginsApiPath'] . "/updateViewerControl", $cfg['remoteToken'], ['viewerControlEnabled' => 'Y']);
?>
