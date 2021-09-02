#!/usr/bin/env php
<?php
$skipJSsettings=true;
include_once "/opt/fpp/www/config.php";
$pluginName = "remote-falcon";

WriteSettingToFile("interrupt_schedule_enabled",urlencode("true"),$pluginName);
WriteSettingToFile("remote_fpp_enabled",urlencode("false"),$pluginName);
WriteSettingToFile("remote_fpp_restarting",urlencode("true"),$pluginName);
?>