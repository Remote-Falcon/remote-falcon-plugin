<h1 style="margin-left: 1em;">Remote Falcon Plugin</h1>
<h3 style="margin-left: 1em;">
	Any time changes are made, go to Content Setup and click "Remote Falcon" to see the changes. 
	After completing the initial steps and/or modifying any of the toggles, you will need to restart FPP. After restarting, it may take up to a minute for the Remote URL 
	to appear on the Remote Falcon Control Panel.
</h3>

<?php
/**GLOBALS */
$pageLocation = "Location: ?plugin=fremote-falcon&page=remote_falcon.php";
$sleepTime = "sleep 1";
$pluginPath = "/home/fpp/media/plugins/remote-falcon";
$scriptPath = "/home/fpp/media/plugins/remote-falcon/scripts";
$remoteFppEnabled = trim(file_get_contents("$pluginPath/remote_fpp_enabled.txt"));
$remoteJukeboxEnabled = trim(file_get_contents("$pluginPath/remote_jukebox_enabled.txt"));

/**FORM FUNCTIONS */
if (isset($_POST['saveRemoteToken'])) {
	$remoteToken = trim($_POST['remoteToken']);
  global $pluginPath;
	shell_exec("rm -f $pluginPath/remote_token.txt");
	shell_exec("echo $remoteToken > $pluginPath/remote_token.txt");
	echo "
		<div style=\"margin-left: 1em;\">
			<h4 style=\"color: #39b54a;\">Remote Token $remoteToken successfully saved. Please refresh the page.</h4>
		</div>
	";
	header("$pageLocation");
}

if (isset($_POST['updateToggles'])) {
  global $pluginPath;
	$remoteFppChecked = "false";
	$remoteJukeboxChecked = "false";
	if (isset($_POST['remoteFppEnabled'])) {
		$remoteFppChecked = "true";
	}
	if (isset($_POST['remoteJukeboxEnabled'])) {
		$remoteJukeboxChecked = "true";
	}
	shell_exec("rm -f $pluginPath/remote_fpp_enabled.txt");
	shell_exec("rm -f $pluginPath/remote_jukebox_enabled.txt");
	shell_exec("echo $remoteFppChecked > $pluginPath/remote_fpp_enabled.txt");
	shell_exec("echo $remoteJukeboxChecked > $pluginPath/remote_jukebox_enabled.txt");
	echo "
		<div style=\"margin-left: 1em;\">
			<h4 style=\"color: #39b54a;\">Toggles have been successfully updated. Please refresh the page.</h4>
		</div>
	";
	header("$pageLocation");
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
		<h5 style=\"margin-left: 1em;\">Adjust the toggles below to turn Remote FPP and Remote Jukebox on or off. Remote FPP is turned on by default. When done, click \"Update Toggles\".</h5>
		<div style=\"margin-left: 1em;\">
			<form method=\"post\">
				<input type=\"checkbox\" name=\"remoteFppEnabled\" id=\"remoteFppEnabled\" checked/> Remote FPP Enabled
	";
}else {
	echo "
		<h3 style=\"margin-left: 1em; color: #39b54a;\">Step 2:</h3>
		<h5 style=\"margin-left: 1em;\">Adjust the toggles below to turn Remote FPP and Remote Jukebox on or off. Remote FPP is turned on by default.</h5>
		<div style=\"margin-left: 1em;\">
			<form method=\"post\">
				<input type=\"checkbox\" name=\"remoteFppEnabled\" id=\"remoteFppEnabled\"/> Remote FPP Enabled
	";
}
echo "<br>";
if(strval($remoteJukeboxEnabled) == "true") {
	echo "
				<input type=\"checkbox\" name=\"remoteJukeboxEnabled\" id=\"remoteJukeboxEnabled\" checked/> Remote Jukebox Enabled
				<br>
				<input id=\"updateTogglesButton\" class=\"button\" name=\"updateToggles\" type=\"submit\" value=\"Update Toggles\"/>
			</form>
		</div>
	";
}else {
	echo "
				<input type=\"checkbox\" name=\"remoteJukeboxEnabled\" id=\"remoteJukeboxEnabled\"/> Remote Jukebox Enabled
				<br>
				<input id=\"updateTogglesButton\" class=\"button\" name=\"updateToggles\" type=\"submit\" value=\"Update Toggles\"/>
			</form>
		</div>
	";
}

if(file_exists("$pluginPath/remote_falcon.log")) {
	echo "
		<h5 style=\"margin-left: 1em;\">Click \"View Remote Falcon Logs\" to see the log file.</h5>
		<div style=\"margin-left: 1em;\">
			<form method=\"post\">
				<input id=\"viewLogsButton\" class=\"button\" name=\"viewLogs\" type=\"submit\" value=\"View Remote Falcon Logs\"/>
			</form>
		</div>
	";
}

if (isset($_POST['viewLogs'])) {
	$logs = file_get_contents("$pluginPath/remote_falcon.log");
	echo "
		<textarea rows=\"10\" cols=\"100\">
			$logs
		</textarea>
	";
}

if(file_exists("$pluginPath/remote_url.txt")) {
	$remoteUrl = file_get_contents("$pluginPath/remote_url.txt");
	$pieces = explode(' ', $remoteUrl);
	$lastWord = array_pop($pieces);
	echo "<br>";
	echo "
		<div style=\"margin-left: 1em;\">
			Your current Remote URL is <strong style=\"color: #39b54a;\">$lastWord</strong>
		</div>
	";
}

?>