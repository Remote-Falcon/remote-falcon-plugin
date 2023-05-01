#!/usr/bin/env php
<?php
$skipJSsettings=true;
include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";
$pluginName = "remote-falcon";

WriteSettingToFile("remoteFalconListenerEnabled",urlencode("false"),$pluginName);
?>