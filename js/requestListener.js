const axios = require('axios');
const winston = require('winston');
const { format } = require('winston');
const { combine, timestamp, printf } = format;
const fs = require('fs');
const readline = require('readline');
const urlUtils = require('./urlUtils');

//Init the logging stuff:
const logFormat = printf(({ level, message, timestamp }) => {
  return `${timestamp} - ${level.toUpperCase()}: ${message}`;
});

const log = winston.createLogger({
  level: 'info',
  format: combine(timestamp(), logFormat),
  transports: [
    new winston.transports.File({ filename: '/home/fpp/media/logs/remote-falcon.log' }),
  ],
});

//Globals
var pluginSettings = {};
const url = (async () => {
  return await urlUtils.getUrl();
});

//Start the main poller:
mainPoller();

async function mainPoller() {
  log.info('Starting Request Listener');
  //One time things are first:
  //Get the settings from plain text config and put them in the pluginSettings global:
  await fetchSettingsFromConfig();
  //Get remaining config from remote pref API call:
  await getRemotePreferences();
  //Log the pluginSettings values so we know what we got:
  await logPluginSettings();

  //Now it's time to start the polling action:
  setInterval(async () => {
    if(pluginSettings.remote_fpp_enabled) {
      var fppStatus = await getFppStatus();
      log.info(fppStatus?.status_name);
    }


    //This should be 500, but setting to 2000 for slow testing:
  }, 2000);
}

//Remote Falcon API Calls
async function getRemotePreferences() {
  const config = {
    headers: { remotetoken: pluginSettings.remoteToken }
  };
  await axios
  .get(`${url.api}/remotePreferences`, config)
  .then(res => {
    if(res.status === 200) {
      pluginSettings.viewerControlMode = res.data.viewerControlMode;
    }else {
      log.error('Error getting remote preferences');
    }
  })
  .catch(err => {
    log.error(err);
  });
}

//FPP API Calls
async function getFppStatus() {
  const config = {
    headers: { 'Content-Type': 'application/json' }
  };
  await axios
  .get(`${url.fpp}/api/fppd/status`, config)
  .then(res => {
    if(res.status === 200) {
      return res.data;
    }else {
      log.error('Error getting FPP status');
      return null;
    }
  })
  .catch(err => {
    log.error(err);
    return null;
  });
}

//Utility functions
async function fetchSettingsFromConfig() {
  const fileStream = fs.createReadStream('/home/fpp/media/config/plugin.remote-falcon');

  const rl = readline.createInterface({
    input: fileStream,
    crlfDelay: Infinity
  });

  for await (const line of rl) {
    var lineSplit = line.split('=');
    pluginSettings[lineSplit[0].trim()] = lineSplit[1].trim().replace(/['"]+/g, '');
  }
}

async function logPluginSettings() {
  log.info(`Plugin Version: ${pluginSettings.pluginVersion}`);
  log.info(`Remote Playlist: ${pluginSettings.remotePlaylist}`);
  log.info(`Viewer Control Mode: ${pluginSettings.viewerControlMode}`);
  log.info(`Interrupt Schedule: ${pluginSettings.interrupt_schedule_enabled}`);
  log.info(`Request Fetch Time: ${pluginSettings.requestFetchTime}`);
  log.info(`Additional Wait Time: ${pluginSettings.additionalWaitTime}`);
}

axios.interceptors.request.use( x => {
  x.meta = x.meta || {}
  x.meta.requestStartedAt = new Date().getTime();
  return x;
})

axios.interceptors.response.use(x => {
  log.info(`Execution time for: ${x.config.url} - ${new Date().getTime() - x.config.meta.requestStartedAt} ms`)
  return x;
},
x => {
  log.error(`Execution time for: ${x.config.url} - ${new Date().getTime() - x.config.meta.requestStartedAt} ms`)
  throw x;
}
)