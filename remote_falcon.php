<h1 style="margin-left: 1em;">Remote Falcon Plugin v4.1.1</h1>
<h4 style="margin-left: 1em;"></h4>

<?php
/**GLOBALS */
$pageLocation = "Location: ?plugin=fremote-falcon&page=remote_falcon.php";
$pluginPath = "/home/fpp/media/plugins/remote-falcon";
$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";
$remoteFppEnabled = trim(file_get_contents("$pluginPath/remote_fpp_enabled.txt"));
$playlists = "";

$url = "http://localhost/api/playlists";
$options = array(
	'http' => array(
		'method'  => 'GET'
		)
);
$context = stream_context_create( $options );
$result = file_get_contents( $url, false, $context );
$response = json_decode( $result, true );
foreach($response as $item) {
	$playlists .= "<option value=\"{$item}\">{$item}</option>";
}

$url = "http://localhost/api/plugin/remote-falcon/updates";
$options = array(
	'http' => array(
		'method'  => 'POST',
		'header'=>  "Content-Type: application/json; charset=UTF-8\r\n" .
								"Accept: application/json\r\n"
		)
);
$context = stream_context_create( $options );
$result = file_get_contents( $url, false, $context );
$response = json_decode( $result, true );
if($response['updatesAvailable'] == 0) {
	echo "
		<h3 style=\"margin-left: 1em; color: #D65A31;\">Remote Falcon Plugin is up to date!</h3>
	";
}else if($response['updatesAvailable'] == 1) {
	echo "
		<h3 style=\"margin-left: 1em; color: #a72525;\">A new update is available for the Remote Falcon Plugin!</h3>
		<h3 style=\"margin-left: 1em; color: #a72525;\">Go to the Plugin Manager to update</h3>
	";
}

if(file_exists("$pluginPath/remote_token.txt")) {
	$remoteToken = file_get_contents("$pluginPath/remote_token.txt");
	if($remoteToken) {
		echo "
			<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 1:</h3>
			<h5 style=\"margin-left: 1em;\">If you need to update your remote token, place it in the input box below and click \"Update Token\".</h5>
			<div style=\"margin-left: 1em;\">
				<form method=\"post\">
					<input type=\"password\" name=\"remoteToken\" id=\"remoteToken\" size=100 value=\"${remoteToken}\">
					<br>
					<input id=\"saveRemoteTokenButton\" class=\"button\" name=\"saveRemoteToken\" type=\"submit\" value=\"Update Token\"/>
				</form>
			</div>
		";
	}
} else {
	echo "
		<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 1:</h3>
		<h5 style=\"margin-left: 1em;\">Place your unique remote token, found on your Remote Falcon Control Panel, in the input box below and click \"Save Token\".</h5>
		<div style=\"margin-left: 1em;\">
			<form method=\"post\">
				<input type=\"password\" name=\"remoteToken\" id=\"remoteToken\" size=100>
				<br>
				<input id=\"saveRemoteTokenButton\" class=\"button\" name=\"saveRemoteToken\" type=\"submit\" value=\"Save Token\"/>
			</form>
		</div>
	";
}
if (isset($_POST['saveRemoteToken'])) {
	$remoteToken = trim($_POST['remoteToken']);
  global $pluginPath;
	shell_exec("rm -f $pluginPath/remote_token.txt");
	shell_exec("echo $remoteToken > $pluginPath/remote_token.txt");
	echo "
		<div style=\"margin-left: 1em;\">
			<h4 style=\"color: #D65A31;\">Remote Token successfully saved.</h4>
		</div>
	";
}

echo "
	<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 2:</h3>
	<h5 style=\"margin-left: 1em;\">
		Pick which playlist you want to sync with Remote Falcon and click \"Sync Playlist\".
		<br />
		Any changes made to the selected playlist will require it to be resynched. 
		If at any time you want to change the synched playlist, simply select the one you want and click \"Sync Playlist\".
	</h5>
	<div style=\"margin-left: 1em;\">
		<form method=\"post\">
			<select id=\"remotePlaylist\" name=\"remotePlaylist\">
				${playlists}
			</select>
			<br>
			<input id=\"saveRemotePlaylistButton\" class=\"button\" name=\"saveRemotePlaylist\" type=\"submit\" value=\"Sync Playlist\"/>
		</form>
	</div>
";
if (isset($_POST['saveRemotePlaylist'])) {
	$remotePlaylist = trim($_POST['remotePlaylist']);
	if(file_exists("$pluginPath/remote_token.txt")) {
		shell_exec("rm -f $pluginPath/remote_playlist.txt");
		shell_exec("echo $remotePlaylist > $pluginPath/remote_playlist.txt");
		$playlists = array();
		$remoteToken = trim(file_get_contents("$pluginPath/remote_token.txt"));
		$url = "http://localhost/api/playlist/${remotePlaylist}";
		$options = array(
			'http' => array(
				'method'  => 'GET'
				)
		);
		$context = stream_context_create( $options );
		$result = file_get_contents( $url, false, $context );
		$response = json_decode( $result, true );
		$mainPlaylist = $response['mainPlaylist'];
		$index = 1;
		foreach($mainPlaylist as $item) {
			if($item['type'] == 'both') {
				$playlist = null;
				$playlist->playlistName = pathinfo($item['sequenceName'], PATHINFO_FILENAME);
				$playlist->playlistDuration = $item['duration'];
				$playlist->playlistIndex = $index;
				array_push($playlists, $playlist);
			}
			$index++;
		}
		$url = "https://remotefalcon.com/remotefalcon/api/syncPlaylists";
		$data = array(
			'playlists' => $playlists
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
		$response = json_decode( $result );
		if($response) {
			echo "
				<div style=\"margin-left: 1em;\">
					<h4 style=\"color: #D65A31;\">Playlist successfully synced!</h4>
				</div>
			";
		}else {
			echo "
				<div style=\"margin-left: 1em;\">
					<h4 style=\"color: #a72525;\">There was an error synching your playlist!</h4>
				</div>
			";
		}
	}else {
		echo "
			<div style=\"margin-left: 1em;\">
				<h4 style=\"color: #a72525;\">You must enter your remote token first!</h4>
			</div>
		";
	}
}

if(strval($remoteFppEnabled) == "true") {
	echo "
		<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 3:</h3>
		<h5 style=\"margin-left: 1em;\">Adjust the toggle below to turn Remote FPP on or off.
		<br />
		This setting is what allows FPP to retrieve viewer requests.
		<br />
		Any time this toggle is modified you must click \"Save Toggle\" and Restart FPP.</h5>
		<div style=\"margin-left: 1em;\">
			<form method=\"post\">
				<input type=\"checkbox\" name=\"remoteFppEnabled\" id=\"remoteFppEnabled\" checked/> Remote FPP Enabled
				<br>
				<input id=\"updateTogglesButton\" class=\"button\" name=\"updateToggles\" type=\"submit\" value=\"Save Toggle\"/>
			</form>
		</div>
	";
}else {
	echo "
		<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 3:</h3>
		<h5 style=\"margin-left: 1em;\">Adjust the toggle below to turn Remote FPP on or off.
		<br />
		This setting is what allows FPP to retrieve viewer requests.
		<br />
		Any time this toggle is modified you must click \"Save Toggle\" and Restart FPP.</h5>
		<div style=\"margin-left: 1em;\">
			<form method=\"post\">
				<input type=\"checkbox\" name=\"remoteFppEnabled\" id=\"remoteFppEnabled\"/> Remote FPP Enabled
				<br>
				<input id=\"updateTogglesButton\" class=\"button\" name=\"updateToggles\" type=\"submit\" value=\"Save Toggle\"/>
			</form>
		</div>
	";
}
if (isset($_POST['updateToggles'])) {
  global $pluginPath;
	$remoteFppChecked = "false";
	if (isset($_POST['remoteFppEnabled'])) {
		$remoteFppChecked = "true";
	}
	shell_exec("rm -f $pluginPath/remote_fpp_enabled.txt");
	shell_exec("echo $remoteFppChecked > $pluginPath/remote_fpp_enabled.txt");
	echo "
		<div style=\"margin-left: 1em;\">
			<h4 style=\"color: #D65A31;\">Toggle has been successfully updated.</h4>
		</div>
	";
	$remoteFppEnabled = trim(file_get_contents("$pluginPath/remote_fpp_enabled.txt"));
}

echo "
		<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 4:</h3>
		<h5 style=\"margin-left: 1em;\">Restart FPP</h5>
	";

echo "
		<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 5:</h3>
		<h5 style=\"margin-left: 1em;\">Profit!</h5>
	";
?>