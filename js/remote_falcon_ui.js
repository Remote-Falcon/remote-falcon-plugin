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
    const tokenValue = $('#remoteTokenInput').val();
    await FPPPost('/api/plugin/remote-falcon/settings/remoteToken', tokenValue, async () => {
      // Add delay to ensure file system writes complete
      await new Promise(resolve => setTimeout(resolve, 500));

      // Verify the setting was actually saved
      const verified = await verifySetting('remoteToken', tokenValue);

      if (verified) {
        await getPluginConfig();
        $.jGrowl("Remote Token Saved", { themeState: 'success' });

        // Add delay before restart to ensure all writes are flushed
        await new Promise(resolve => setTimeout(resolve, 300));
        await restartListener();
      } else {
        $.jGrowl("Error: Remote Token failed to save. Please try again or restart FPP.", { themeState: 'danger' });
      }
    }, (xhr, status, error) => {
      $.jGrowl("Error saving Remote Token: " + error, { themeState: 'danger' });
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

  $('#loadConfigButton').click(async () => {
    await loadPluginConfig();
  });

  $('#saveConfigButton').click(async () => {
    await savePluginConfig();
  });

  $('#resetConfigButton').click(async () => {
    await resetPluginConfigToDefault();
  });
});

async function init() {
  showLoader();

  // Set a maximum timeout to ensure loader is hidden even if something hangs
  const loaderTimeout = setTimeout(() => {
    console.warn('Init timeout reached - forcing loader to hide');
    hideLoader();
    $.jGrowl("Plugin loaded with warnings - some features may not be available", { themeState: 'warning' });
  }, 10000); // 10 second timeout

  try {
    // This only happens one time
    try {
      await saveDefaultPluginConfig();
    } catch (error) {
      console.error('Error saving default config:', error);
      $.jGrowl("Warning: Could not save default config", { themeState: 'warning' });
    }

    // Set the config globals
    try {
      await getPluginConfig();
    } catch (error) {
      console.error('Error getting plugin config:', error);
      $.jGrowl("Warning: Could not load plugin config", { themeState: 'warning' });
    }

    try {
      await getPlaylists();
    } catch (error) {
      console.error('Error getting playlists:', error);
      $.jGrowl("Warning: Could not load playlists", { themeState: 'warning' });
    }

    try {
      await checkPluginUpdates();
    } catch (error) {
      console.error('Error checking plugin updates:', error);
      // Silent fail for updates check
    }

    if(REMOTE_TOKEN && REMOTE_TOKEN !== '') {
      try {
        await savePluginVersionAndFPPVersionToRF();
      } catch (error) {
        console.error('Error saving plugin version to RF:', error);
        // Silent fail for version reporting
      }

      try {
        await checkPlugin();
      } catch (error) {
        console.error('Error checking plugin:', error);
        $.jGrowl("Warning: Could not run plugin check", { themeState: 'warning' });
      }
    }
  } catch (error) {
    console.error('Unexpected error during init:', error);
    $.jGrowl("Error during initialization", { themeState: 'danger' });
  } finally {
    // Always clear the timeout and hide the loader
    clearTimeout(loaderTimeout);
    hideLoader();
  }
}

async function syncPlaylistToRF() {
  if(REMOTE_TOKEN) {
    var selectedPlaylist = $('#remotePlaylistSelect').val();
    await FPPGet('/api/playlist/' + encodeURIComponent(selectedPlaylist), async (data) => {
      var totalItems = data?.playlistInfo?.total_items;
      if(PLUGINS_API_PATH.includes("remotefalcon.com") && totalItems > 200) {
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
  }, (xhr, status, error) => {
    console.error('RFAPIGet Error:', status, error);
    hideLoader();
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

async function loadPluginConfig() {
  await FPPGet('/api/configfile/plugin.remote-falcon', (data) => {
    $('#pluginConfigTextarea').val(data);
    $.jGrowl("Config Loaded", { themeState: 'success' });
  });
}

async function savePluginConfig() {
  const configContent = $('#pluginConfigTextarea').val();

  if (!configContent || configContent.trim() === '') {
    $.jGrowl("Config cannot be empty", { themeState: 'danger' });
    return;
  }

  await $.ajax({
    url: '/api/configfile/plugin.remote-falcon',
    type: 'POST',
    contentType: 'text/plain',
    data: configContent,
    async: true,
    success: async () => {
      $.jGrowl("Config Saved Successfully", { themeState: 'success' });

      // Reload the plugin config globals
      await getPluginConfig();

      // Prompt to restart listener
      if (confirm('Config saved. Do you want to restart the listener for changes to take effect?')) {
        await restartListener();
        // Refresh the page after restart
        setTimeout(() => {
          location.reload();
        }, 1000);
      } else {
        // Refresh page even if they don't restart
        setTimeout(() => {
          location.reload();
        }, 500);
      }
    },
    error: (_jqXHR, status, error) => {
      console.error('Save Config Error:', status, error);
      $.jGrowl("Error saving config: " + error, { themeState: 'danger' });
    }
  });
}

async function resetPluginConfigToDefault() {
  if (!confirm('Are you sure you want to reset the config to default values? This will overwrite all current settings!')) {
    return;
  }

  // Create default config based on saveDefaultPluginConfig values
  const defaultConfig = `init = "true"
remoteToken = ""
remoteFalconListenerEnabled = "true"
remoteFalconListenerRestarting = "false"
interruptSchedule = "false"
requestFetchTime = "3"
additionalWaitTime = "0"
fppStatusCheckTime = "1"
pluginsApiPath = "${DEFAULT_PLUGINS_API_PATH}"
verboseLogging = "false"`;

  $('#pluginConfigTextarea').val(defaultConfig);

  await $.ajax({
    url: '/api/configfile/plugin.remote-falcon',
    type: 'POST',
    contentType: 'text/plain',
    data: defaultConfig,
    async: true,
    success: async () => {
      $.jGrowl("Config Reset to Default Successfully", { themeState: 'success' });

      // Reload the plugin config globals
      await getPluginConfig();

      // Prompt to restart listener
      if (confirm('Config reset complete. Do you want to restart the listener for changes to take effect?')) {
        await restartListener();
        // Refresh the page after restart
        setTimeout(() => {
          location.reload();
        }, 1000);
      } else {
        // Refresh page even if they don't restart
        setTimeout(() => {
          location.reload();
        }, 500);
      }
    },
    error: (_jqXHR, status, error) => {
      console.error('Reset Config Error:', status, error);
      $.jGrowl("Error resetting config: " + error, { themeState: 'danger' });
    }
  });
}
