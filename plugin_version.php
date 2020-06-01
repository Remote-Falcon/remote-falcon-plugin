<?php
$pluginPath = "/home/fpp/media/plugins/remote-falcon";

if(file_exists("$pluginPath/remote_token.txt")) {
	$remoteToken = trim(file_get_contents("$pluginPath/remote_token.txt"));
	$pluginVersion = "2.0.0";
	
	$url = "https://remotefalcon.com/remotefalcon/api/pluginVersion";
	$data = array(
		'pluginVersion' => $pluginVersion
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