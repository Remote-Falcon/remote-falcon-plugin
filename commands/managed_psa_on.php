#!/usr/bin/env php
<?php
$skipJSsettings=true;
include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";
$pluginName = "remote-falcon";
$pluginConfigFile = $settings['configDirectory'] . "/plugin.remote-falcon";
$pluginSettings = parse_ini_file($pluginConfigFile);

$remoteToken = urldecode($pluginSettings['remoteToken']);
$pluginsApiPath = urldecode($pluginSettings['pluginsApiPath']);

if(strlen($remoteToken)>1) {
	$url = $pluginsApiPath . "/updateManagedPsa";
	$data = array(
		'managedPsaEnabled' => 'Y'
	);
	$options = array(
		'http' => array(
			'method'  => 'POST',
			'content' => json_encode( $data ),
			'header'=>  "Content-Type: application/json; charset=UTF-8\r\n" .
									"Accept: application/json\r\n" .
									"remotetoken: $remoteToken\r\n"
			)
	);
	$context = stream_context_create( $options );
	$result = file_get_contents( $url, false, $context );
}
?>
