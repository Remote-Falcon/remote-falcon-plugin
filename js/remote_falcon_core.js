//Config Globals
const DEFAULT_PLUGINS_API_PATH = 'https://remotefalcon.com/remote-falcon-plugins-api';

var PLUGIN_VERSION = null;
var REMOTE_TOKEN = null;
var REMOTE_FALCON_LISTENER_ENABLED = null;
var REMOTE_FALCON_LISTENER_RESTARTING = null;
var INTERRUPT_SCHEDULE = null;
var REQUEST_FETCH_TIME = null;
var ADDITIONAL_WAIT_TIME = null;
var FPP_STATUS_CHECK_TIME = null;
var REMOTE_PLAYLIST = null;
var PLUGINS_API_PATH = DEFAULT_PLUGINS_API_PATH;
var VERBOSE_LOGGING = null;
var AUTO_SYNC_METADATA = false;

async function saveDefaultPluginConfig() {
  await FPPGet('/api/plugin/remote-falcon/settings/init', async (data) => {
    var init = data?.init;
    if(!init) {
      const noop = () => {};
      await Promise.all([
        FPPPost('/api/plugin/remote-falcon/settings/init', 'true', noop),
        FPPPost('/api/plugin/remote-falcon/settings/remoteToken', '', noop),
        FPPPost('/api/plugin/remote-falcon/settings/remoteFalconListenerEnabled', 'true', noop),
        FPPPost('/api/plugin/remote-falcon/settings/remoteFalconListenerRestarting', 'false', noop),
        FPPPost('/api/plugin/remote-falcon/settings/interruptSchedule', 'false', noop),
        FPPPost('/api/plugin/remote-falcon/settings/requestFetchTime', '3', noop),
        FPPPost('/api/plugin/remote-falcon/settings/additionalWaitTime', '0', noop),
        FPPPost('/api/plugin/remote-falcon/settings/fppStatusCheckTime', '1', noop),
        FPPPost('/api/plugin/remote-falcon/settings/pluginsApiPath', PLUGINS_API_PATH, noop),
        FPPPost('/api/plugin/remote-falcon/settings/verboseLogging', 'false', noop),
        FPPPost('/api/plugin/remote-falcon/settings/autoSyncMetadata', 'false', noop),
      ]);
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
    await RFAPIPost('/pluginVersion', request, () => {},
      (xhr, status, error) => {
      console.error('RFAPIPost Error:', status, error);
      hideLoader();
    });
  });
}

async function getPluginConfig() {
  await Promise.all([
    FPPGet('/api/plugin/remote-falcon/settings/pluginVersion', (data) => {
      PLUGIN_VERSION = data?.pluginVersion;
    }),
    FPPGet('/api/plugin/remote-falcon/settings/remoteToken', (data) => {
      REMOTE_TOKEN = data?.remoteToken;
    }),
    FPPGet('/api/plugin/remote-falcon/settings/remoteFalconListenerEnabled', (data) => {
      REMOTE_FALCON_LISTENER_ENABLED = data?.remoteFalconListenerEnabled == 'true';
    }),
    FPPGet('/api/plugin/remote-falcon/settings/remoteFalconListenerRestarting', (data) => {
      REMOTE_FALCON_LISTENER_RESTARTING = data?.remoteFalconListenerRestarting == 'true';
    }),
    FPPGet('/api/plugin/remote-falcon/settings/interruptSchedule', (data) => {
      INTERRUPT_SCHEDULE = data?.interruptSchedule == 'true';
    }),
    FPPGet('/api/plugin/remote-falcon/settings/requestFetchTime', (data) => {
      REQUEST_FETCH_TIME = parseInt(data?.requestFetchTime);
    }),
    FPPGet('/api/plugin/remote-falcon/settings/additionalWaitTime', (data) => {
      ADDITIONAL_WAIT_TIME = parseInt(data?.additionalWaitTime);
    }),
    FPPGet('/api/plugin/remote-falcon/settings/fppStatusCheckTime', (data) => {
      FPP_STATUS_CHECK_TIME = parseFloat(data?.fppStatusCheckTime);
    }),
    FPPGet('/api/plugin/remote-falcon/settings/pluginsApiPath', (data) => {
      PLUGINS_API_PATH = decodeURIComponent(data?.pluginsApiPath);
    }),
    FPPGet('/api/plugin/remote-falcon/settings/verboseLogging', (data) => {
      VERBOSE_LOGGING = data?.verboseLogging == 'true';
    }),
    FPPGet('/api/plugin/remote-falcon/settings/autoSyncMetadata', (data) => {
      AUTO_SYNC_METADATA = data?.autoSyncMetadata == 'true';
    }),
  ]);
  await getRemotePlaylistFromConfig();
}

async function getRemotePlaylistFromConfig() {
  await FPPGet('/api/configfile/plugin.remote-falcon', (data) => {
    if (!data || typeof data !== 'string') {
      REMOTE_PLAYLIST = null;
      return;
    }
    const match = data.match(/remotePlaylist\s*=\s*"([^"]*)"/);
    if (!match || !match[1]) {
      REMOTE_PLAYLIST = null;
      return;
    }
    let value = match[1];
    try {
      value = decodeURIComponent(value);
    } catch (e) {
      // Malformed encoding — fall back to the raw match.
    }
    REMOTE_PLAYLIST = normalizePlaylistName(value);
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
    const normalizedRemote = normalizePlaylistName(REMOTE_PLAYLIST);
    data.forEach(playlist => {
      const normalizedPlaylist = normalizePlaylistName(playlist);
      const selectedAttr = normalizedRemote && normalizedPlaylist === normalizedRemote ? ' selected' : '';
      playlistOptions += '<option' + selectedAttr + ' value="' + playlist + '">' + playlist + '</option>';
    });
    $('#remotePlaylistSelect').html(playlistOptions);
    if (normalizedRemote) {
      $('#remotePlaylistSelect').val(REMOTE_PLAYLIST);
    }
  })
}

function normalizePlaylistName(name) {
  return typeof name === 'string' ? name.trim() : '';
}

async function restartListener() {
  $.jGrowl("Restarting Listener", { themeState: 'success' });
  await FPPPost('/api/plugin/remote-falcon/settings/remoteFalconListenerEnabled', 'false', () => {});
  await FPPPost('/api/plugin/remote-falcon/settings/remoteFalconListenerRestarting', 'true', () => {});
  await getPluginConfig();

  await checkPlugin();

  $('#remoteFalconStatus').html(getRemoteFalconListenerEnabledStatus(REMOTE_FALCON_LISTENER_ENABLED));

  var listenerCameBack = false;
  var checkListenerStatus = setInterval(async () => {
    await getPluginConfig();
    $('#remoteFalconStatus').html(getRemoteFalconListenerEnabledStatus(REMOTE_FALCON_LISTENER_ENABLED));
    if(REMOTE_FALCON_LISTENER_ENABLED) {
      listenerCameBack = true;
      $.jGrowl("Listener Restarted", { themeState: 'success' });
      clearInterval(checkListenerStatus);
    }
  }, 1000);

  setTimeout(() => {
    clearInterval(checkListenerStatus);
    if (!listenerCameBack) {
      $.jGrowl("Listener did not restart in time. Try Restart Listener again or check the listener log.", { themeState: 'danger' });
    }
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

// Verify a setting was saved by reading it back
async function verifySetting(settingKey, expectedValue, maxRetries = 3, delayMs = 200) {
  for (let i = 0; i < maxRetries; i++) {
    await new Promise(resolve => setTimeout(resolve, delayMs));

    let actualValue = null;
    await FPPGet('/api/plugin/remote-falcon/settings/' + settingKey, (data) => {
      actualValue = data?.[settingKey];
    });

    if (actualValue === expectedValue) {
      return true;
    }
  }
  return false;
}

//AJAX Helper Functions
async function FPPGet(url, successCallback) {
  await $.ajax({
    url,
    type: 'GET',
    async: true,
    headers: {
      'Cache-Control': 'no-cache',
      'Pragma': 'no-cache'
    },
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

async function FPPPost(url, data, successCallback, errorCallback = null) {
  await $.ajax({
    url,
    type: 'POST',
    contentType: 'application/text',
    dataType: 'text',
    data,
    async: true,
    success: (data, statusText, xhr) => {
      successCallback(data, statusText, xhr);
    },
    error: (xhr, status, error) => {
      if (errorCallback) {
        errorCallback(xhr, status, error);
      } else {
        console.error('FPPPost Error:', status, error);
        $.jGrowl("Error saving setting: " + error, { themeState: 'danger' });
      }
    }
  });
}

async function RFAPIGet(url, successCallback, errorCallback = null) {
  await $.ajax({
    url: PLUGINS_API_PATH + url,
    type: 'GET',
    async: true,
    headers: {'remotetoken': REMOTE_TOKEN},
    success: (data, statusText, xhr) => {
      successCallback(data, statusText, xhr);
    },
    error: (xhr, status, error) => {
      if (errorCallback) {
        errorCallback(xhr, status, error);
      } else {
        console.error('RFAPIGet Error:', status, error);
      }
    }
  });
}

async function RFAPIPost(url, data, successCallback, errorCallback = null) {
  await $.ajax({
    url: PLUGINS_API_PATH + url,
    type: 'POST',
    contentType: 'application/json',
    dataType: 'json',
    data: JSON.stringify(data),
    async: true,
    headers: { 'remotetoken': REMOTE_TOKEN },
    success: (data, statusText, xhr) => {
      successCallback(data, statusText, xhr);
    },
    error: (xhr, status, error) => {
      if (errorCallback) {
        errorCallback(xhr, status, error);
      } else {
        console.error('RFAPIGet Error:', status, error);
      }
    }
  });
}
