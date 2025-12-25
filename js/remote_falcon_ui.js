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
  $('#autoSyncMetadataCheckbox').prop('checked', AUTO_SYNC_METADATA);

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
  
  $('#testConnectivityButton').click(async () => {
    await runConnectivityTest(true);
  });
  
  $('#tailLogButton').click(async () => {
    await tailListenerLog();
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

  $('#autoSyncMetadataCheckbox').change(async () => {
    const isChecked = $('#autoSyncMetadataCheckbox').is(':checked');
    AUTO_SYNC_METADATA = isChecked;
    await FPPPost('/api/plugin/remote-falcon/settings/autoSyncMetadata', isChecked.toString(), () => {
      $.jGrowl(isChecked ? "Metadata sync enabled" : "Metadata sync disabled", { themeState: 'success' });
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
  updateLoaderStatus("Starting up...");

  // Set a maximum timeout to ensure loader is hidden even if something hangs
  const loaderTimeout = setTimeout(() => {
    console.warn('Init timeout reached - forcing loader to hide');
    hideLoader();
    $.jGrowl("Plugin loaded with warnings - some features may not be available", { themeState: 'warning' });
  }, 6000); // shorter timeout so UI shows quickly

  try {
    updateLoaderStatus("Saving defaults...");
    try {
      await saveDefaultPluginConfig();
    } catch (error) {
      console.error('Error saving default config:', error);
      $.jGrowl("Warning: Could not save default config", { themeState: 'warning' });
    }

    updateLoaderStatus("Loading configuration...");
    try {
      await getPluginConfig();
    } catch (error) {
      console.error('Error getting plugin config:', error);
      $.jGrowl("Warning: Could not load plugin config", { themeState: 'warning' });
    }

    updateLoaderStatus("Loading playlists...");
    try {
      await getPlaylists();
    } catch (error) {
      console.error('Error getting playlists:', error);
      $.jGrowl("Warning: Could not load playlists", { themeState: 'warning' });
    }

    // At this point the UI is usable; hide loader and run the rest in the background
    hideLoader();
    clearTimeout(loaderTimeout);

    // Background non-blocking tasks
    setTimeout(async () => {
      try {
        await checkPluginUpdates();
      } catch (error) {
        console.error('Error checking plugin updates:', error);
        // Silent fail for updates check
      }

      if(REMOTE_TOKEN && REMOTE_TOKEN !== '') {
        try {
          $('#connectivityStatus').text('Testing connectivity...');
          await runConnectivityTest(false);
        } catch (error) {
          console.error('Error testing connectivity during init:', error);
        }

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
      } else {
        $('#connectivityStatus').text('Add Show Token to test connectivity');
      }
    }, 0);
  } catch (error) {
    console.error('Unexpected error during init:', error);
    $.jGrowl("Error during initialization", { themeState: 'danger' });
  } finally {
    // Always clear the timeout and hide the loader
    clearTimeout(loaderTimeout);
    hideLoader();
  }
}

function updateLoaderStatus(message) {
  $('#loaderStatus').text(message);
}

function getPlaylistReadableName(playlist) {
  if (!playlist) {
    return '';
  }
  if (playlist?.sequenceName) {
    return playlist.sequenceName.split('.')[0];
  }
  if (playlist?.mediaName) {
    return playlist.mediaName.split('.')[0];
  }
  if (playlist?.note) {
    return playlist.note;
  }
  return '';
}

function parseAlbumArtUrl(commentTag) {
  if (!commentTag || typeof commentTag !== 'string') {
    return '';
  }
  let candidate = commentTag.trim();
  if (candidate.endsWith(',')) {
    candidate = candidate.slice(0, -1).trim();
  }
  if (candidate.startsWith('"') && candidate.endsWith('"')) {
    candidate = candidate.slice(1, -1).trim();
  }
  return isImageUrl(candidate) ? candidate : '';
}

function isImageUrl(url) {
  return /^https?:\/\/\S+\.(png|jpe?g|gif|webp|bmp|svg)(\?\S*)?$/i.test(url);
}

function ensureSyncOverlay() {
  if ($('#rfSyncProgressOverlay').length) {
    return;
  }
  const overlay = $(`
    <div id="rfSyncProgressOverlay" class="rf-sync-overlay" style="display:none;">
      <div class="rf-sync-dialog">
        <div class="rf-sync-title">Syncing with Remote Falcon</div>
        <div id="rfSyncProgressText" class="rf-sync-text">Preparing...</div>
        <div id="rfSyncItemText" class="rf-sync-item"></div>
        <div class="rf-sync-bar">
          <div id="rfSyncProgressBar" class="rf-sync-bar-fill"></div>
        </div>
      </div>
    </div>
  `);
  $('body').append(overlay);
}

function updateSyncProgress(message, percent = null, itemLabel = null) {
  ensureSyncOverlay();
  if (message) {
    $('#rfSyncProgressText').text(message);
  }
  if (itemLabel !== null) {
    $('#rfSyncItemText').text(itemLabel);
  }
  if (percent !== null && !Number.isNaN(percent)) {
    const clamped = Math.max(0, Math.min(100, percent));
    $('#rfSyncProgressBar').css('width', clamped + '%');
  }
  $('#rfSyncProgressOverlay').fadeIn(100);
}

function hideSyncProgress() {
  $('#rfSyncProgressOverlay').fadeOut(150);
}

async function fetchFppData(url) {
  let result = null;
  try {
    await FPPGet(url, (data) => {
      result = data;
    });
  } catch (error) {
    console.error('FPPGet failed for', url, error);
  }
  return result;
}

async function syncPlaylistToRF() {
  if(REMOTE_TOKEN) {
    var selectedPlaylist = $('#remotePlaylistSelect').val();
    const shouldSyncMetadata = $('#autoSyncMetadataCheckbox').is(':checked');
    updateSyncProgress('Loading playlist...', 10);

    try {
      const data = await fetchFppData('/api/playlist/' + encodeURIComponent(selectedPlaylist));
      if(!data) {
        $.jGrowl("Unable to load playlist", { themeState: 'danger' });
        return;
      }

      var totalItems = data?.playlistInfo?.total_items;
      const playlistItems = data?.mainPlaylist || [];
      const totalCount = playlistItems.length;

      if(PLUGINS_API_PATH.includes("remotefalcon.com") && totalItems > 200) {
        $.jGrowl("Cannot sync more than 200 items", { themeState: 'danger' });
        return;
      }

      var sequences = [];
      var playlistIndex = 1;
      var processed = 0;

      for (const playlist of playlistItems) {
        processed++;
        const readableName = getPlaylistReadableName(playlist);
        updateSyncProgress(
          `Gathering metadata ${processed}/${totalCount}`,
          totalCount ? (Math.round((processed / totalCount) * 70) + 10) : 30,
          readableName
        );

        if(playlist?.type == 'both') {
          if (shouldSyncMetadata) {
            const mediaData = await fetchFppData('/api/media/' + encodeURIComponent(playlist?.mediaName) + "/meta");
            const mediaAlbumUrl = parseAlbumArtUrl(mediaData?.format?.tags?.comment);
            sequences.push({
              playlistName: playlist?.sequenceName?.split('.')[0],
              playlistDuration: playlist?.duration,
              playlistIndex: playlistIndex,
              playlistType: 'SEQUENCE',
              mediaTitle: mediaData?.format?.tags?.title ? mediaData?.format?.tags?.title : '',
              mediaArtist: mediaData?.format?.tags?.artist ? mediaData?.format?.tags?.artist : '',
              mediaAlbumUrl: mediaAlbumUrl,
            });
          } else {
            sequences.push({
              playlistName: playlist?.sequenceName?.split('.')[0],
              playlistDuration: playlist?.duration,
              playlistIndex: playlistIndex,
              playlistType: 'SEQUENCE',
            });
          }
        }else if(playlist?.type === 'sequence') {
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
      }

      if(sequences.length === 0) {
        $.jGrowl("Playlist is Empty", { themeState: 'danger' });
        return;
      }

      updateSyncProgress('Syncing with Remote Falcon...', 90, '');

      await RFAPIPost('/syncPlaylists', {playlists: sequences}, async (data, statusText, xhr) => {
        if(xhr?.status === 200) {
          updateSyncProgress('Sync complete', 100);
          REMOTE_PLAYLIST = $('#remotePlaylistSelect').val();
          await FPPPost('/api/plugin/remote-falcon/settings/remotePlaylist', REMOTE_PLAYLIST, async () => {
            $.jGrowl("Remote Playlist Saved", { themeState: 'success' });
            await restartListener();
          });
        }
      }, (xhr, status, error) => {
        console.error('syncPlaylists failed:', status, error);
        $.jGrowl("Error syncing playlists", { themeState: 'danger' });
      });
    } catch (error) {
      console.error('Sync to RF failed:', error);
      $.jGrowl("Error syncing playlists", { themeState: 'danger' });
    } finally {
      setTimeout(() => hideSyncProgress(), 200);
    }
  }else {
    $.jGrowl("Remote Token Missing", { themeState: 'danger' });
  }
}

async function checkPlugin() {
  $('#checkPluginResults').html('');
  $("#checkPluginResults").removeClass('good warning');

  var checkPluginResults = [];

  if(REMOTE_TOKEN == null || REMOTE_TOKEN === '') {
    checkPluginResults.push('Remote Token has not been entered.');
  } else {
    const connectivity = await runConnectivityTest(false);
    if(!connectivity?.ok) {
      const connectivityError = connectivity?.error ? ` (${connectivity.error})` : '';
      checkPluginResults.push('Plugin is unable to reach the Remote Falcon API.' + connectivityError);
    }
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

async function runConnectivityTest(showToast = false) {
  const $status = $('#connectivityStatus');
  if($status.length) {
    $status.removeClass('good warning');
    $status.text('Testing connectivity...');
  }

  try {
    const start = performance.now();
    let result = null;
    await RFAPIGet('/q/health', (data, _statusText, xhr) => {
      const latency = performance.now() - start;
      result = {
        ok: xhr?.status === 200 && data?.status && data.status.toUpperCase() === 'UP',
        status: data?.status,
        latencyMs: Math.round(latency),
        error: null
      };
    }, (xhr, status, error) => {
      console.error('RFAPIGet Error:', status, error);
      result = { ok: false, error: error || status };
    });

    if(result?.ok) {
      if($status.length) {
        const latencyText = result.latencyMs != null ? ` (${result.latencyMs} ms)` : '';
        $status.text('Remote Falcon API reachable' + latencyText);
        $status.addClass('good');
      }
      if(showToast) {
        const latencyText = result.latencyMs != null ? ` in ${result.latencyMs} ms` : '';
        $.jGrowl("Remote Falcon API reachable" + latencyText, { themeState: 'success' });
      }
      return result;
    }

    const errorLabel = result?.error ? result.error : 'status_not_up';
    if($status.length) {
      $status.text('Remote Falcon API unreachable');
      $status.addClass('warning');
    }
    if(showToast) {
      $.jGrowl("Remote Falcon API unreachable: " + errorLabel, { themeState: 'danger' });
    }
    return result;
  } catch (error) {
    console.error('Connectivity test failed:', error);
    if($status.length) {
      $status.text('Connectivity test failed');
      $status.addClass('warning');
    }
    if(showToast) {
      $.jGrowl("Connectivity test failed", { themeState: 'danger' });
    }
    return { ok: false, error: 'ajax_failed' };
  }
}

async function stopListener() {
  await FPPPost('/api/plugin/remote-falcon/settings/remoteFalconListenerEnabled', 'false', () => {});
  await getPluginConfig();
  $('#remoteFalconStatus').html(getRemoteFalconListenerEnabledStatus(REMOTE_FALCON_LISTENER_ENABLED));
  $.jGrowl("Stopped Listener", { themeState: 'success' });
}

async function tailListenerLog() {
  const $status = $('#tailLogStatus');
  const $output = $('#tailLogOutput');

  if($status.length) {
    $status.text('Loading...');
  }
  if($output.length) {
    $output.text('');
  }

  try {
    await FPPGet('/api/file/Logs/remote-falcon-listener.log?tail=50', (data) => {
      if($output.length) {
        $output.text(data);
      }
      if($status.length) {
        $status.text('Updated');
      }
    }, (_xhr, status, error) => {
      console.error('Tail log error:', status, error);
      if($status.length) {
        $status.text('Failed to load log');
      }
      $.jGrowl("Failed to load listener log", { themeState: 'danger' });
    });
  } catch (error) {
    console.error('Tail log exception:', error);
    if($status.length) {
      $status.text('Failed to load log');
    }
    $.jGrowl("Failed to load listener log", { themeState: 'danger' });
  }
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
verboseLogging = "false"
autoSyncMetadata = "false"`;

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
