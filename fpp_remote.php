<?php
$pluginPath = "/home/fpp/media/plugins/remote-falcon";
$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";
$fppRemoteScript = file_get_contents("$scriptPath/fpp_remote_v2.sh");
$date = date("h:i:s");

shell_exec("rm -f $pluginPath/remote_falcon.log");
shell_exec("echo Starting fpp_remote.php at $date > $pluginPath/remote_falcon.log");

if(file_exists("$pluginPath/remote_token.txt")) {
	$remoteToken = trim(file_get_contents("$pluginPath/remote_token.txt"));
	appendLog("Found remote token $remoteToken");
	appendLog("Creating Remote URL");
	shell_exec('chmod +x /home/fpp/media/plugins/remote-falcon/scripts/fpp_remote_v2.sh');
	$pid = exec("sh /home/fpp/media/plugins/remote-falcon/scripts/fpp_remote_v2.sh> /dev/null 2>&1 & echo $!; ", $output);
	appendLog("Waiting for script to execute");
	sleep(15);
	if(file_exists("$pluginPath/remote_url.txt")) {
		$remoteUrl = file_get_contents("$pluginPath/remote_url.txt");
		$pieces = explode(' ', $remoteUrl);
		$lastWord = "";
		$lastWord = trim(array_pop($pieces));
		if (strpos($lastWord, 'https://') === false) {
				foreach ($pieces as &$value) {
					if (strpos($value, 'https://') !== false && strpos($value, 'localhost.run') !== false) {
							$lastWord = trim($value);
					}
			}
		}
		if($lastWord == "") {
			appendLog("Error creating Remote URL");
		}else {
			appendLog("Created Remote URL $lastWord");
			appendLog("Sending Remote URL to Remote Falcon");
			$url = "https://remotefalcon.com/cgi-bin/rmrghbsEvMhSH8LKuJydVn23pvsFKX/saveRemoteByKey.php";
			$data = array(
				'remoteKey' => $remoteToken,
				'remoteURL' => $lastWord
			);
			$options = array(
				'http' => array(
					'method'  => 'POST',
					'content' => json_encode( $data ),
					'header'=>  "Content-Type: application/json\r\n" .
											"Accept: application/json\r\n"
					)
			);
			appendLog("POST: " . json_encode( $data ));
			$context  = stream_context_create( $options );
			$result = file_get_contents( $url, false, $context );
			$response = json_decode( $result );
			if($response === true) {
				appendLog("Successfully saved Remote URL to Remote Falcon");
			}else {
				appendLog("Error saving Remote URL to Remote Falcon: $response");
			}
		}
	}else {
		appendLog("Error occured when creating Remote URL");
	}
}

function appendLog($log) {
	global $pluginPath;
	if(file_exists("$pluginPath/remote_falcon.log")) {
		$logFile = "$pluginPath/remote_falcon.log";
		$current = file_get_contents($logFile);
		$current .= $log . "\n";
		file_put_contents($logFile, $current);
	}
}
?>