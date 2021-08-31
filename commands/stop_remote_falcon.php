<?php
include_once "/opt/fpp/www/common.php";
$pluginName = "remote-falcon";

WriteSettingToFile("remote_fpp_enabled",urlencode("false"),$pluginName);
?>