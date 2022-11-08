$(document).ready(function() {
  getPluginConfig();
});

async function getPluginConfig() {
  const response = await fetch('http://localhost:8090/api/configfile/remote-falcon.json', {method: 'GET'});
  var pluginConfig = await response.json();
  document.getElementById("pluginVersion").innerHTML = pluginConfig.pluginVersion;
  document.getElementById("remoteToken").value = pluginConfig.remoteToken;
  document.getElementById("requestFetchTime").value = pluginConfig.requestFetchTime;
}

function saveRemoteToken() {
  var remoteToken = document.getElementById('remoteToken').value;
  $.jGrowl('Remote Token Updated', {themeState:'success'});
}

function saveRemotePlaylist() {
  var remotePlaylist = document.getElementById('remotePlaylist').value;
  $.jGrowl('Remote Playlist Updated', {themeState:'success'});
}

function saveRequestFetchTime() {
  var requestFetchTime = document.getElementById('requestFetchTime').value;
  $.jGrowl('Request Fetch Time Updated', {themeState:'success'});
}