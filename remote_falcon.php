<?php
include_once "/opt/fpp/www/common.php"; //Alows use of FPP Functions
$pluginName = basename(dirname(__FILE__));
$pluginConfigFile = $settings['configDirectory'] ."/plugin." .$pluginName; //gets path to configuration files for plugin
    
if (file_exists($pluginConfigFile)) {
	$pluginSettings = parse_ini_file($pluginConfigFile);
}

$pluginVersion = "5.0.0";

WriteSettingToFile("pluginVersion",urlencode($pluginVersion),$pluginName);

echo "
	<h1 style=\"margin-left: 1em;\">Remote Falcon Plugin v{$pluginVersion}</h1>
	<h4 style=\"margin-left: 1em;\"></h4>
";

$remotePlaylist=urldecode($pluginSettings['remotePlaylist']);// get settings
$remoteFppEnabled=urldecode($pluginSettings['remote_fpp_enabled']);
$interruptScheduleEnabled=urldecode($pluginSettings['interrupt_schedule_enabled']);	
$remoteToken= urldecode($pluginSettings['remoteToken']);
$playlistDirectory= $settings['playlistDirectory'];
$playlists = "";

$url = "http://127.0.0.1/api/playlists";
$options = array(
	'http' => array(
		'method'  => 'GET'
		)
);

if (is_dir($playlistDirectory)){
	$playlistDropdown=array();
	if ($dirTemp = opendir($playlistDirectory)){
		while (($fileRead = readdir($dirTemp)) !== false){
			if (($fileRead == ".") || ($fileRead == "..")){
				continue;
			}
			$fileRead=pathinfo($fileRead, PATHINFO_FILENAME);
			$playlistDropdown[$fileRead]=$fileRead;
		}
	  closedir($dirTemp);
	}
}

if (is_dir($playlistDirectory)){
	
	if ($dirTemp = opendir($playlistDirectory)){
		while (($fileRead = readdir($dirTemp)) !== false){
			if (($fileRead == ".") || ($fileRead == "..")){
				continue;
			}
			$fileRead=pathinfo($fileRead, PATHINFO_FILENAME);
			if ($fileRead==$remotePlaylist){
				$playlists .="<option value=\"{$fileRead}\" selected>{$fileRead}</option>";
			}else{
				$playlists .="<option value=\"{$fileRead}\">{$fileRead}</option>";
			}
		}
	  closedir($dirTemp);
	}
}
$url = "http://127.0.0.1/api/plugin/remote-falcon/updates";
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
if($response['updatesAvailable'] == 1) {
	echo "
		<h3 style=\"margin-left: 1em; color: #a72525;\">A new update is available for the Remote Falcon Plugin!</h3>
		<h3 style=\"margin-left: 1em; color: #a72525;\">Go to the Plugin Manager to update</h3>
	";
}

echo "
	<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 1:</h3>
	<h5 style=\"margin-left: 1em;\">If you need to update your remote token, place it in the input box below.</h5>
	<div style=\"margin-left: 1em;\">";
		PrintSettingTextSaved("remoteToken", $restart = 0, $reboot = 0, $maxlength = 32, $size = 32, $pluginName = $pluginName, $defaultValue = "Enter Your Token");
	echo "</div>
	";

echo "
	<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 2:</h3>
	<h5 style=\"margin-left: 1em;\">
		Pick which playlist you want to sync with Remote Falcon and click \"Sync Playlist\". The playlist you sync with RF should be 
		its own playlist that is not used in any schedules.
		<br />
		Any changes made to the selected playlist will require it to be resynched. 
		If at any time you want to change the synched playlist, simply select the one you want and click \"Sync Playlist\".
		<br />";
if(strlen($remotePlaylist)<2){
	$remotePlaylist= "NO PLAYLIST CURRENTLY SAVED";
}
echo "
		<p>Current Synched Playlist-    <b> {$remotePlaylist}</b>
		</p>
	</h5>
	<div style=\"margin-left: 1em;\">
		<form method=\"post\">
";
PrintSettingSelect("remotePlaylist", "remotePlaylist", $restart = 0, $reboot = 0, "No playlists are saved", $playlistDropdown, $pluginName = $pluginName, $callbackName = "", $changedFunction = "", $sData = Array());
echo "<input id=\"saveRemotePlaylistButton\" class=\"button\" name=\"saveRemotePlaylist\" type=\"submit\" value=\"Sync Playlist\"/>
		</form>
	</div>
";
if (isset($_POST['saveRemotePlaylist'])) {
	$remotePlaylist=urldecode($pluginSettings['remotePlaylist']);
	if(strlen($remoteToken)>1) {
		//WriteSettingToFile("remotePlaylist",urlencode($_POST["remotePlaylist"]),$pluginName);
		$playlists = array();
		$remotePlaylistEncoded = str_replace(' ', '%20', $remotePlaylist);// change to urlencode?
		$url = "http://127.0.0.1/api/playlist/${remotePlaylistEncoded}";
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
			if($item['type'] == 'both' || $item['type'] == 'sequence') {
				$playlist = null;
				$playlist->playlistName = pathinfo($item['sequenceName'], PATHINFO_FILENAME);
				$playlist->playlistDuration = $item['duration'];
				$playlist->playlistIndex = $index;
				array_push($playlists, $playlist);
			}else if($item['type'] == 'media') {
				$playlist = null;
				$playlist->playlistName = pathinfo($item['mediaName'], PATHINFO_FILENAME);
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
echo "
		<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 3:</h3>
		<h5 style=\"margin-left: 1em;\">Adjust the toggle below to turn Remote FPP on or off.
		<br />
		This setting is what allows FPP to retrieve viewer requests.
		<br />
		Any time this toggle is modified you must Restart FPP.</h5>
		<div style=\"margin-left: 1em;\">
		<b>Enable Remote Falcon</b> ";
			PrintSettingCheckbox("Remote Falcon", "remote_fpp_enabled", $restart = 1, $reboot = 0, "true", "false", $pluginName = $pluginName, $callbackName = "", $defaultValue = 0, $desc = "", $sData = Array());
echo "		
		</div>
	";

echo "
		<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 4:</h3>
		<h5 style=\"margin-left: 1em;\">Adjust the toggle below to choose if you want the scheduled playlist to be interrupted when a request is received.
		<br />
		Default is on, meaning the scheduled playlist will be interrupted with a new request
		<br />
		Any time this toggle is modified you must Restart FPP.</h5>
		<div style=\"margin-left: 1em;\">
		<b>Interrupt Schedule</b> ";
		PrintSettingCheckbox("Interrupt Schedule", "interrupt_schedule_enabled", $restart = 1, $reboot = 0, "true", "false", $pluginName = $pluginName, $callbackName = "", $defaultValue = 0, $desc = "", $sData = Array());
echo "
		</div>
	";

echo "
		<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 5:</h3>
		<h5 style=\"margin-left: 1em;\">Restart FPP</h5>
	";

echo "
		<h3 style=\"margin-left: 1em; color: #D65A31;\">Step 6:</h3>
		<h5 style=\"margin-left: 1em;\">Profit!</h5>
	";

echo "
		<h5 style=\"margin-left: 1em;\">While Remote Falcon is 100% free for users, there are still associated costs with owning and maintaining a server and 
		database. If you would like to help support Remote Falcon you can donate using the button below.</h5>
		<h5 style=\"margin-left: 1em;\">Donations will <strong>never</strong> be required but will <strong>always</strong> be appreciated.</h5>
		<a href=\"https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=FFKWA2CFP6JC6&currency_code=USD&source=url\" target=\"_blank\"> <img style=\"margin-left: 1em;\" alt=\"RF_Donate\" src=\"https://remotefalcon.com/support-button.png\"></a>
	";

echo "
	<h5 style=\"margin-left: 1em;\">Changelog:</h5>
	<ul>
	<li>
		<strong>5.0.0: Big thanks to Rick Harris for all the improvements in this version!</strong>
		<ul>
			<li>
				Plugin now works for all schedule types!
			</li>
			<li>
				Main plugin page updated to use FPP common functions to improve toggles. This change also ensures plugin updates 
				work properly.
			</li>
		</ul>
	</li>
		<li>
			<strong>4.6.0</strong>
			<ul>
				<li>
					Checking schedule times in addition to the day
				</li>
				<li>
					More logging so you know things about things
				</li>
				<li>
					Fix repeating sequence for schedules that end at 24:00:00.
				</li>
			</ul>
		</li>
	</ul>
";
?>
