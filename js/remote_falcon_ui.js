$(document).ready(async () => {
  await init();

  //Component Default States
  $('#pluginVersion').html(PLUGIN_VERSION);
  $('#remoteFalconStatus').html(getRemoteFalconListenerEnabledStatus(REMOTE_FALCON_LISTENER_ENABLED));
  $('#remoteTokenInput').val(REMOTE_TOKEN ? REMOTE_TOKEN : '');
  $('#interruptScheduleCheckbox').prop('checked', INTERRUPT_SCHEDULE);
  $('#requestFetchTimeInput').val(REQUEST_FETCH_TIME);
  $('#requestFetchTime').html(REQUEST_FETCH_TIME);
  $('#additionalWaitTimeInput').val(ADDITIONAL_WAIT_TIME);
  $('#fppStatusCheckTimeInput').val(FPP_STATUS_CHECK_TIME);
  $('#pluginsApiPathInput').val(PLUGINS_API_PATH);
  $('#verboseLoggingCheckbox').prop('checked', VERBOSE_LOGGING);

  //Component Handlers
  $('#remoteTokenInput').blur(async () => {
    await FPPPost('/api/plugin/remote-falcon/settings/remoteToken', $('#remoteTokenInput').val(), async () => {
      await getPluginConfig();
      $.jGrowl("Remote Token Saved", { themeState: 'success' });
      await restartListener();
    });
  });

  $('#syncRemotePlaylistButton').click(async () => {
    await syncPlaylistToRF();
  });

  $('#interruptScheduleCheckbox').change(async () => {
    const isChecked = $('#interruptScheduleCheckbox').is(':checked');
    await FPPPost('/api/plugin/remote-falcon/settings/interruptSchedule', isChecked.toString(), async () => {
      await getPluginConfig();
      $.jGrowl(isChecked ? "Interrupt Scheduled Enabled" : "Interrupt Scheduled Disabled", { themeState: 'success' });
      await restartListener();
    });
  });
  
  $('#restartListenerButton').click(async () => {
    await restartListener();
  });
  
  $('#checkPluginButton').click(async () => {
    await checkPlugin();
  });

  $('#requestFetchTimeInput').blur(async () => {
    await FPPPost('/api/plugin/remote-falcon/settings/requestFetchTime', $('#requestFetchTimeInput').val().toString(), async () => {
      await getPluginConfig();
      $.jGrowl("Request/Vote Fetch Time Saved", { themeState: 'success' });
      await restartListener();
    });
  });

  $('#additionalWaitTimeInput').blur(async () => {
    await FPPPost('/api/plugin/remote-falcon/settings/additionalWaitTime', $('#additionalWaitTimeInput').val().toString(), async () => {
      await getPluginConfig();
      $.jGrowl("Additional Wait Time Saved", { themeState: 'success' });
      await restartListener();
    });
  });

  $('#fppStatusCheckTimeInput').blur(async () => {
    await FPPPost('/api/plugin/remote-falcon/settings/fppStatusCheckTime', $('#fppStatusCheckTimeInput').val().toString(), async () => {
      await getPluginConfig();
      $.jGrowl("FPP Status Check Time Saved", { themeState: 'success' });
      await restartListener();
    });
  });

  $('#pluginsApiPathInput').blur(async () => {
    await FPPPost('/api/plugin/remote-falcon/settings/pluginsApiPath', $('#pluginsApiPathInput').val().toString(), async () => {
      await getPluginConfig();
      $.jGrowl("Plugins API Path Saved", { themeState: 'success' });
      await restartListener();
    });
  });

  $('#verboseLoggingCheckbox').change(async () => {
    const isChecked = $('#verboseLoggingCheckbox').is(':checked');
    await FPPPost('/api/plugin/remote-falcon/settings/verboseLogging', isChecked.toString(), async () => {
      await getPluginConfig();
      $.jGrowl(isChecked ? "Verbose Logging Enabled" : "Verbose Logging Disabled", { themeState: 'success' });
      await restartListener();
    });
  });

  $('#stopListenerButton').click(async () => {
    await stopListener();
  });
});

async function init() {
  showLoader();

  //This only happens one time
  await saveDefaultPluginConfig();

  //Set the config globals
  await getPluginConfig();

  await savePluginVersionAndFPPVersionToRF();
  await checkPluginUpdates();
  await getPlaylists();
  
  await checkPlugin();
  
  hideLoader();
}

async function syncPlaylistToRF() {
  if(REMOTE_TOKEN) {
    var selectedPlaylist = $('#remotePlaylistSelect').val();
    await FPPGet('/api/playlist/' + encodeURIComponent(selectedPlaylist), async (data) => {
      var totalItems = data?.playlistInfo?.total_items;
      if(totalItems > 200) {
        $.jGrowl("Cannot sync more than 200 items", { themeState: 'danger' });
      }else {
        var sequences = [];
        if(data?.mainPlaylist) {
          var playlistIndex = 1;
          data?.mainPlaylist.forEach(playlist => {
            if(playlist?.type === 'sequence' || playlist?.type === 'both') {
              sequences.push({
                playlistName: playlist?.sequenceName?.split('.')[0],
                playlistDuration: playlist?.duration,
                playlistIndex: playlistIndex,
                playlistType: 'SEQUENCE',
              });
            }else if(playlist?.type === 'media') {
              sequences.push({
                playlistName: playlist?.mediaName?.split('.')[0],
                playlistDuration: 0,
                playlistIndex: playlistIndex,
                playlistType: 'MEDIA',
              });
            }else if(playlist?.type === 'command' && playlist?.note != null) {
              sequences.push({
                playlistName: playlist?.note,
                playlistDuration: 0,
                playlistIndex: playlistIndex,
                playlistType: 'COMMAND',
              });
            }
            playlistIndex++;
          })
        }
        if(sequences.length === 0) {
          $.jGrowl("Playlist is Empty", { themeState: 'danger' });
        }else {
          await RFAPIPost('/syncPlaylists', {playlists: sequences}, async (data, statusText, xhr) => {
            if(xhr?.status === 200) {
              REMOTE_PLAYLIST = $('#remotePlaylistSelect').val();
              await FPPPost('/api/plugin/remote-falcon/settings/remotePlaylist', REMOTE_PLAYLIST, async () => {
                $.jGrowl("Remote Playlist Saved", { themeState: 'success' });
                await restartListener();
              });
            }
          })
        }
      }
    });
  }else {
    $.jGrowl("Remote Token Missing", { themeState: 'danger' });
  }
}

async function checkPlugin() {
  $('#checkPluginResults').html('');

  var checkPluginResults = [];

  await RFAPIGet('/actuator/health', (data, statusText, xhr) => {
    if(xhr?.status !== 200 || data?.status !== 'UP') {
      checkPluginResults.push('Plugin is unable to reach the Remote Falcon API.');
    }
  });

  if(REMOTE_TOKEN == null || REMOTE_TOKEN === '') {
    checkPluginResults.push('Remote Token has not been entered.');
  }

  if(REMOTE_PLAYLIST == null || REMOTE_PLAYLIST === '') {
    checkPluginResults.push('Remote Playlist has not been synced.');
  }

  await FPPGet('/api/playlist/' + encodeURIComponent(REMOTE_PLAYLIST), async (data) => {
    if(data?.leadIn?.length > 0 || data?.leadOut?.length > 0) {
      checkPluginResults.push('Remote Playlist should not contain lead in or lead out items.');
    }
  });

  await FPPGet('/api/fppd/schedule', async (data) => {
    if(data?.schedule?.entries?.length > 0) {
      data?.schedule?.entries.forEach(scheduleEntry => {
        if(scheduleEntry?.playlist === REMOTE_PLAYLIST) {
          checkPluginResults.push('Remote Playlist should not be part of any schedules.');
        }
      });
    }
  });

  if(checkPluginResults.length === 0) {
    $('#checkPluginResults').html('No issues found with the plugin.');
    $("#checkPluginResults").addClass('good');
  }

  checkPluginResults.forEach(result => {
    $('#checkPluginResults').append(result + '<br />');
    $("#checkPluginResults").addClass('warning');
  });
}

async function stopListener() {
  await FPPPost('/api/plugin/remote-falcon/settings/remoteFalconListenerEnabled', 'false', () => {});
  await getPluginConfig();
  $('#remoteFalconStatus').html(getRemoteFalconListenerEnabledStatus(REMOTE_FALCON_LISTENER_ENABLED));
  $.jGrowl("Stopped Listener", { themeState: 'success' });
}
