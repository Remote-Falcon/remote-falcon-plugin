<?php
include_once "/opt/fpp/www/common.php";
include_once "/home/fpp/media/plugins/remote-falcon/baseurl.php";
$baseUrl = getBaseUrl();
$pluginName = basename(dirname(__FILE__));
$pluginConfigFile = $settings['configDirectory'] . "/plugin." .$pluginName;
$pluginSettings = parse_ini_file($pluginConfigFile);

$pluginVersion = urldecode($pluginSettings['pluginVersion']);
$remoteToken = urldecode($pluginSettings['remoteToken']);

if(strlen($remoteToken)>1) {
	$url = "http://127.0.0.1/api/fppd/version";
	$options = array(
		'http' => array(
			'method'  => 'GET'
			)
	);
	$context = stream_context_create( $options );
	$result = file_get_contents( $url, false, $context );
	$response = json_decode( $result );
	$fppVersion = $response->version;
	
	$url = $baseUrl . "/remotefalcon/api/pluginVersion";
	$data = array(
		'pluginVersion' => $pluginVersion,
		'fppVersion' => $fppVersion
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