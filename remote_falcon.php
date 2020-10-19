<?php
include_once "/opt/fpp/www/common.php"; //Alows use of FPP Functions
$pluginName = basename(dirname(__FILE__));
$pluginConfigFile = $settings['configDirectory'] ."/plugin." .$pluginName; //gets path to configuration files for plugin
    
if (file_exists($pluginConfigFile)) {
	$pluginSettings = parse_ini_file($pluginConfigFile);
}

$pluginVersion = "5.0.2";

//foreach below will read all of the settings and thier values instead of reading each one individually
//settings saved are:
//remote_fpp_enabled
//interrupt_schedule_enabled
//pluginVersion
//remotePlaylist
//remoteToken

foreach ($pluginSettings as $key => $value) { 
	${$key} = urldecode($value);
}
//set defaults if nothing saved
if (strlen(urldecode($pluginSettings['remotePlaylist']))<1){
	WriteSettingToFile("remotePlaylist",urlencode(""),$pluginName);
}
if (strlen(urldecode($pluginSettings['interrupt_schedule_enabled']))<1){
	WriteSettingToFile("interrupt_schedule_enabled",urlencode("false"),$pluginName);
}
if (strlen(urldecode($pluginSettings['remote_fpp_enabled']))<1){
	WriteSettingToFile("remote_fpp_enabled",urlencode("false"),$pluginName);
}
if (strlen(urldecode($pluginSettings['remoteToken']))<1){
	WriteSettingToFile("remoteToken",urlencode(""),$pluginName);
}

WriteSettingToFile("pluginVersion",urlencode($pluginVersion),$pluginName);

if(strlen($remotePlaylist)<2){
	$remotePlaylist= "NO PLAYLIST CURRENTLY SAVED";
	$remotePlaylistStyle="color: #ff0000";
}

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
	$playlistDropdown[""]="";
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
if ($response['updatesAvailable'] == 1) {//show/hide the updates available section
	$showUpdateDiv= "display:block";
}else{
	$showUpdateDiv= "display:none";
}
$syncResultDiv= "display:none";
$syncResultMessage="";

if (isset($_POST['saveRemotePlaylist'])) { 
	$remotePlaylist=urldecode($pluginSettings['remotePlaylist']);
	if (strlen($remotePlaylist)>2){
		if(strlen($remoteToken)>1) {
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
			if($response) { //Sync sucess maybe get response from server on valid Playlist
				$syncResultDiv= "color: #D65A31; display:block;";
				$syncResultMessage= "Playlist successfully synced!";
					
			}else { //sync failure- maybe get server to respond with more info like invalid token, empty playlist etc?
				$syncResultMessage= "There was an error synching your playlist! <br />Make sure you have the correct token";
				$syncResultDiv= "color: #ff0000; display:block; background-color: black;";
				WriteSettingToFile("remotePlaylist","",$pluginName);	
			}
		}else {//remote token has not been saved
			$syncResultDiv= "color: #ff0000; display:block; background-color: black;";
			$syncResultMessage= "You must enter your remote token first!";
			WriteSettingToFile("remotePlaylist","",$pluginName);
		}
	}else{
		$syncResultDiv= "color: #ff0000; display:block; background-color: black;";
		$syncResultMessage= "You didn't select a Playlist";
		WriteSettingToFile("remotePlaylist","",$pluginName);	
	}
}

?>

<!DOCTYPE html>
<html>
<head>

</head>
<body>
<div class="pluginBody" style="margin-left: 1em;">
	<div class="title">
		<h1>Remote Falcon Plugin v<? echo $pluginVersion; ?></h1>
		<h4></h4>
	</div>
	<div id="showUpdate" style=" <? echo "$showUpdateDiv"; ?>">
		<h3 style="color: #a72525;">A new update is available for the Remote Falcon Plugin!</h3>
		<h3 style="color: #a72525;">Go to the Plugin Manager to update</h3>
	</div>

	<h3 style="color: #D65A31;">Step 1:</h3>
	<h5>If you need to update your remote token, place it in the input box below.</h5>
	<div>
<?
PrintSettingTextSaved("remoteToken", $restart = 0, $reboot = 0, $maxlength = 32, $size = 32, $pluginName = $pluginName, $defaultValue = "Enter Your Token");
?>
	</div>	
		<h3 style="color: #D65A31;">Step 2:</h3>
		<h5>Pick which playlist you want to sync with Remote Falcon and click "Sync Playlist". The playlist you sync with RF should be 
		its own playlist that is not used in any schedules.
		<br />
		Any changes made to the selected playlist will require it to be resynched. 
		If at any time you want to change the synched playlist, simply select the one you want and click "Sync Playlist".
		<br />
		<div id="remotePlaylistDiv" style= "<? echo "$remotePlaylistStyle" ?>" >
			<h2>Current Synched Playlist-    <b> <? echo "$remotePlaylist "; ?></b>
			</h2>
		</div>
	<div>
		<form method="post">
<?
PrintSettingSelect("remotePlaylist", "remotePlaylist", $restart = 1, $reboot = 0, "No playlists are saved", $playlistDropdown, $pluginName = $pluginName, $callbackName = "", $changedFunction = "", $sData = Array());
?>
			<input id="saveRemotePlaylistButton" class="button" name="saveRemotePlaylist" type="submit" value="Sync Playlist"/>
		</form>
		<div id="syncResult" style="<? echo "$syncResultDiv" ?>">
			<div>
				<h4><? echo "$syncResultMessage"; ?></h4>
			</div>
		</div>
	</div>
		<h3 style="color: #D65A31;">Step 3:</h3>
		<h5>Adjust the toggle below to turn Remote FPP on or off.
		<br />
		This setting is what allows FPP to retrieve viewer requests.
		<br />
		Any time this toggle is modified you must Restart FPP.</h5>
		<div>
			<b>Enable Remote Falcon</b> 
<?			
PrintSettingCheckbox("Remote Falcon", "remote_fpp_enabled", $restart = 1, $reboot = 0, "true", "false", $pluginName = $pluginName, $callbackName = "", $defaultValue = 0, $desc = "", $sData = Array());
?>
		</div>
		<h3 style="color: #D65A31;">Step 4:</h3>
		<h5>Adjust the toggle below to choose if you want the scheduled playlist to be interrupted when a request is received.
		<br />
		Default is on, meaning the scheduled playlist will be interrupted with a new request
		<br />
		Any time this toggle is modified you must Restart FPP.</h5>
		<div>
			<b>Interrupt Schedule</b> 
<?
PrintSettingCheckbox("Interrupt Schedule", "interrupt_schedule_enabled", $restart = 1, $reboot = 0, "true", "false", $pluginName = $pluginName, $callbackName = "", $defaultValue = 0, $desc = "", $sData = Array());
?>
		</div>
		<h3 style="#D65A31;">Step 5:</h3>
		<h5>Restart FPP</h5>
		<h3 style="color: #D65A31;">Step 6:</h3>
		<h5>Profit!</h5>
		<h5>While Remote Falcon is 100% free for users, there are still associated costs with owning and maintaining a server and 
		database. If you would like to help support Remote Falcon you can donate using the button below.</h5>
		<h5>Donations will <strong>never</strong> be required but will <strong>always</strong> be appreciated.</h5>
		<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=FFKWA2CFP6JC6&currency_code=USD&source=url" target="_blank"> <img style="margin-left: 1em;" alt="RF_Donate" src="https://remotefalcon.com/support-button.png"></a>
	
	
</div>
</body>
</html>
