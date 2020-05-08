<h1 style="margin-left: 1em;">Remote Falcon Plugin</h1>
<h3 style="margin-left: 1em;"></h3>

<?php
/**GLOBALS */
$pageLocation = "Location: ?plugin=fremote-falcon&page=remote_falcon.php";
$pluginPath = "/home/fpp/media/plugins/remote-falcon";
$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";
$remoteFppEnabled = trim(file_get_contents("$pluginPath/remote_fpp_enabled.txt"));

/**FORM FUNCTIONS */
if (isset($_POST['saveRemoteToken'])) {
	$remoteToken = trim($_POST['remoteToken']);
  global $pluginPath;
	shell_exec("rm -f $pluginPath/remote_token.txt");
	shell_exec("echo $remoteToken > $pluginPath/remote_token.txt");
	echo "
		<div style=\"margin-left: 1em;\">
			<h4 style=\"color: #39b54a;\">Remote Token $remoteToken successfully saved.</h4>
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
			<h4 style=\"color: #39b54a;\">Toggle has been successfully updated.</h4>
		</div>
	";
	$remoteFppEnabled = trim(file_get_contents("$pluginPath/remote_fpp_enabled.txt"));
}

/**PLUGIN UI */
if(file_exists("$pluginPath/remote_token.txt")) {
	$remoteToken = file_get_contents("$pluginPath/remote_token.txt");
	if($remoteToken) {
		echo "
			<h3 style=\"margin-left: 1em; color: #39b54a;\">Step 1:</h3>
			<h5 style=\"margin-left: 1em;\">If you need to update your remote token, place it in the input box below and click \"Update Token\".</h5>
			<div style=\"margin-left: 1em;\">
				<form method=\"post\">
					Your Remote Token: <input type=\"text\" name=\"remoteToken\" id=\"remoteToken\" size=100 value=\"${remoteToken}\">
					<br>
					<input id=\"saveRemoteTokenButton\" class=\"button\" name=\"saveRemoteToken\" type=\"submit\" value=\"Update Token\"/>
				</form>
			</div>
		";
	}
} else {
	echo "
		<h3 style=\"margin-left: 1em; color: #39b54a;\">Step 1:</h3>
		<h5 style=\"margin-left: 1em;\">Place your unique remote token, found on your Remote Falcon Control Panel, in the input box below and click \"Save Token\".</h5>
		<div style=\"margin-left: 1em;\">
			<form method=\"post\">
				<input type=\"text\" name=\"remoteToken\" id=\"remoteToken\" size=100>
				<br>
				<input id=\"saveRemoteTokenButton\" class=\"button\" name=\"saveRemoteToken\" type=\"submit\" value=\"Save Token\"/>
			</form>
		</div>
	";
}

echo "<br>";
if(strval($remoteFppEnabled) == "true") {
	echo "
		<h3 style=\"margin-left: 1em; color: #39b54a;\">Step 2:</h3>
		<h5 style=\"margin-left: 1em;\">Adjust the toggle below to turn Remote FPP on or off. Remote FPP is turned off by default. 
		This setting is what retrieves the viewer requested playlists. 
		When done, click \"Save Toggle\" and Restart FPP.</h5>
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
		<h3 style=\"margin-left: 1em; color: #39b54a;\">Step 2:</h3>
		<h5 style=\"margin-left: 1em;\">Adjust the toggle below to turn Remote FPP on or off. Remote FPP is turned off by default. 
		This setting is what retrieves the viewer requested playlists. 
		When done, click \"Save Toggle\" and Restart FPP.</h5>
		<div style=\"margin-left: 1em;\">
			<form method=\"post\">
				<input type=\"checkbox\" name=\"remoteFppEnabled\" id=\"remoteFppEnabled\"/> Remote FPP Enabled
				<br>
				<input id=\"updateTogglesButton\" class=\"button\" name=\"updateToggles\" type=\"submit\" value=\"Save Toggle\"/>
			</form>
		</div>
	";
}

echo "<br>";
echo "
		<h3 style=\"margin-left: 1em; color: #39b54a;\">Step 3:</h3>
		<h5 style=\"margin-left: 1em;\">Restart FPP</h5>
	";

echo "<br>";
echo "
		<h3 style=\"margin-left: 1em; color: #39b54a;\">Step 4:</h3>
		<h5 style=\"margin-left: 1em;\">Profit!</h5>
	";

echo "<br>";
echo "
	<h5 style=\"margin-left: 1em;\">To manually update the playlists on Remote Falcon, click \"Update Playlists\" below. 
	Playlists will update automatically every 4 hours.</h5>
	<div style=\"margin-left: 1em;\">
		<form method=\"post\">
			<input id=\"sendPlaylistsButton\" class=\"button\" name=\"sendPlaylists\" type=\"submit\" value=\"Update Playlists\"/>
		</form>
	</div>
";

if (isset($_POST['sendPlaylists'])) {
	shell_exec('/usr/bin/php /home/fpp/media/plugins/remote-falcon/remote_playlist_manual_sync.php');
}

echo "<br>";
echo "
	<h5 style=\"margin-left: 1em;\">If you're having issues getting playlists to sync, click \"Send Debug Report\". 
	This will send a report that will allow us to figure out why the playlists are not synching.</h5>
	<div style=\"margin-left: 1em;\">
		<form method=\"post\">
			<input id=\"sendDebugReportButton\" class=\"button\" name=\"sendDebugReport\" type=\"submit\" value=\"Send Debug Report\"/>
		</form>
	</div>
";

if (isset($_POST['sendDebugReport'])) {
	shell_exec('/usr/bin/php /home/fpp/media/plugins/remote-falcon/remote_playlist_debug.php');
}
?>