<?php
$pluginPath = "/home/fpp/media/plugins/remote-falcon";
$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";
$fppRemoteScript = trim(file_get_contents("$scriptPath/fpp_remote.sh"));

shell_exec("rm -f $pluginPath/remote_falcon.log");

appendLog("Starting fpp_remote.php");

if(file_exists("$pluginPath/remote_token.txt")) {
	$remoteToken = trim(file_get_contents("$pluginPath/remote_token.txt"));
	appendLog("Found remote token $remoteToken");
}

function appendLog($log) {
	global $pluginPath;
	$logFile = "$pluginPath/remote_falcon.log";
	$current = file_get_contents($logFile);
	$current .= $log;
	file_put_contents($file, $current);
}
?>