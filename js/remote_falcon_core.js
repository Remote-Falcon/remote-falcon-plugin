//Globals
var API_URL = 'https://remotefalcon.com/remotefalcon/api';

//Config Globals
var PLUGIN_VERSION = null;
var REMOTE_TOKEN = null;
var REMOTE_FALCON_LISTENER_ENABLED = null;
var REMOTE_FALCON_LISTENER_RESTARTING = null;
var INTERRUPT_SCHEDULE = null;
var REQUEST_FETCH_TIME = null;
var ADDITIONAL_WAIT_TIME = null;
var FPP_STATUS_CHECK_TIME = null;
var REMOTE_PLAYLIST = null;


function setApiUrl() {
  var hostname = $(location).attr('hostname');
  if(hostname === 'localhost') {
    API_URL = 'http://host.docker.internal:8080/remotefalcon/api'
  }
}

async function saveDefaultPluginConfig() {
  await FPPGet('/api/plugin/remote-falcon/settings/init', async (data) => {
    var init = data?.init;
    if(!init) {
      await FPPPut('/api/plugin/remote-falcon/settings/init', 'true', () => {});
      await FPPPut('/api/plugin/remote-falcon/settings/remoteFalconListenerEnabled', 'true', () => {});
      await FPPPut('/api/plugin/remote-falcon/settings/remoteFalconListenerRestarting', 'false', () => {});
      await FPPPut('/api/plugin/remote-falcon/settings/interruptSchedule', 'false', () => {});
      await FPPPut('/api/plugin/remote-falcon/settings/requestFetchTime', '3', () => {});
      await FPPPut('/api/plugin/remote-falcon/settings/additionalWaitTime', '0', () => {});
      await FPPPut('/api/plugin/remote-falcon/settings/fppStatusCheckTime', '1', () => {});
      $.jGrowl("Default Config Saved", { themeState: 'success' });
    }
  })
}

async function savePluginVersionAndFPPVersionToRF() {
  await FPPGet('/api/fppd/version', async (data) => {
    var request = {
      pluginVersion: PLUGIN_VERSION,
      fppVersion: data?.version
    }
    await RFAPIPost('/pluginVersion', request, () => {});
  });
}

async function getPluginConfig() {
  await FPPGet('/api/plugin/remote-falcon/settings/pluginVersion', (data) => {
    PLUGIN_VERSION = data?.pluginVersion;
  });
  await FPPGet('/api/plugin/remote-falcon/settings/remoteToken', (data) => {
    REMOTE_TOKEN = data?.remoteToken;
  });
  await FPPGet('/api/plugin/remote-falcon/settings/remoteFalconListenerEnabled', (data) => {
    REMOTE_FALCON_LISTENER_ENABLED = data?.remoteFalconListenerEnabled == 'true';
  });
  await FPPGet('/api/plugin/remote-falcon/settings/remoteFalconListenerRestarting', (data) => {
    REMOTE_FALCON_LISTENER_RESTARTING = data?.remoteFalconListenerRestarting == 'true';
  });
  await FPPGet('/api/plugin/remote-falcon/settings/interruptSchedule', (data) => {
    INTERRUPT_SCHEDULE = data?.interruptSchedule == 'true';
  });
  await FPPGet('/api/plugin/remote-falcon/settings/requestFetchTime', (data) => {
    REQUEST_FETCH_TIME = parseInt(data?.requestFetchTime);
  });
  await FPPGet('/api/plugin/remote-falcon/settings/additionalWaitTime', (data) => {
    ADDITIONAL_WAIT_TIME = parseInt(data?.additionalWaitTime);
  });
  await FPPGet('/api/plugin/remote-falcon/settings/fppStatusCheckTime', (data) => {
    FPP_STATUS_CHECK_TIME = parseInt(data?.fppStatusCheckTime);
  });
  await FPPGet('/api/plugin/remote-falcon/settings/remotePlaylist', (data) => {
    REMOTE_PLAYLIST = data?.remotePlaylist;
  });
}

function getRemoteFalconListenerEnabledStatus(remoteFalconListenerEnabled) {
  if(remoteFalconListenerEnabled) {
    return '<h4 style="color:green;">Remote Falcon Listener is Running</h4>';
  }
  return '<h4 style="color:red;">Remote Falcon Listener is Stopped</h4>';
}

async function checkPluginUpdates() {
  await FPPPost('/api/plugin/remote-falcon/updates', {}, (data) => {
    if(data?.updatesAvailable === 1) {
      $('#updatesAvailable').html('<h4 style="color:red;">A Plugin Update is Available</h4>');
    }
  })
}

async function getPlaylists() {
  await FPPGet('/api/playlists', (data) => {
    var playlistOptions = '';
    data.forEach(playlist => {
      if(playlist === REMOTE_PLAYLIST) {
        playlistOptions += '<option selected value="' + playlist + '">' + playlist + '</option>';
      }else {
        playlistOptions += '<option value="' + playlist + '">' + playlist + '</option>';
      }
    });
    $('#remotePlaylistSelect').html(playlistOptions);
  })
}

async function restartListener() {
  $.jGrowl("Restarting Listener", { themeState: 'success' });
  await FPPPut('/api/plugin/remote-falcon/settings/remoteFalconListenerEnabled', 'false', () => {});
  await FPPPut('/api/plugin/remote-falcon/settings/remoteFalconListenerRestarting', 'true', () => {});
  await getPluginConfig();
  $('#remoteFalconStatus').html(getRemoteFalconListenerEnabledStatus(REMOTE_FALCON_LISTENER_ENABLED));

  var checkListenerStatus = setInterval(async () => {
    await getPluginConfig();
    $('#remoteFalconStatus').html(getRemoteFalconListenerEnabledStatus(REMOTE_FALCON_LISTENER_ENABLED));
    if(REMOTE_FALCON_LISTENER_ENABLED) {
      $.jGrowl("Listener Restarted", { themeState: 'success' });
      clearInterval(checkListenerStatus);
    }
  }, 1000);

  setTimeout(async () => {
    clearInterval(checkListenerStatus);
  }, 5000);
}

function showLoader() {
  $("#loader").css({ 'display' : 'block'});
  $(".plugin-body").css({ 'display' : 'none'});
}

function hideLoader() {
  $("#loader").css({ 'display' : 'none'});
  $(".plugin-body").css({ 'display' : 'block'});
}

//AJAX Helper Functions
async function FPPGet(url, successCallback) {
  await $.ajax({
    url,
    type: 'GET',
    async: true,
    success: (data, statusText, xhr) => {
      successCallback(data, statusText, xhr);
    }
  });
}

async function FPPPut(url, data, successCallback) {
  await $.ajax({
    url,
    type: 'PUT',
    contentType: 'application/json',
    dataType: 'json',
    data,
    async: true,
    success: (data, statusText, xhr) => {
      successCallback(data, statusText, xhr);
    }
  });
}

async function FPPPost(url, data, successCallback) {
  await $.ajax({
    url,
    type: 'POST',
    contentType: 'application/json',
    dataType: 'json',
    data,
    async: true,
    success: (data, statusText, xhr) => {
      successCallback(data, statusText, xhr);
    }
  });
}

async function RFAPIGet(url, successCallback) {
  await $.ajax({
    url: API_URL + url,
    type: 'GET',
    async: true,
    headers: { 'remotetoken': REMOTE_TOKEN },
    success: (data, statusText, xhr) => {
      successCallback(data, statusText, xhr);
    }
  });
}

async function RFAPIPost(url, data, successCallback) {
  await $.ajax({
    url: API_URL + url,
    type: 'POST',
    contentType: 'application/json',
    dataType: 'json',
    data: JSON.stringify(data),
    async: true,
    headers: { 'remotetoken': REMOTE_TOKEN },
    success: (data, statusText, xhr) => {
      successCallback(data, statusText, xhr);
    }
  });
}