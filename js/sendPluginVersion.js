const axios = require('axios');
const winston = require('winston');
const { format } = require('winston');
const { combine, timestamp, printf } = format;
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

const url = (async () => {
  return await urlUtils.getUrl();
});

sendPluginVersion();

async function sendPluginVersion() {
  log.info('Sending plugin version to RF');
  var pluginConfig = await getPluginConfig();
  var fppdVersion = await getFppdVersion();
  if(pluginConfig && pluginConfig.remoteToken) {
    const config = {
      headers: { remotetoken: pluginConfig.remoteToken }
    };
    const data = {
      pluginVersion: pluginConfig.pluginVersion,
      fppVersion: fppdVersion?.version
    };
    await axios
    .post(`${url.api}/pluginVersion`, data, config)
    .then(res => {
      if(res.status === 200) {
        log.info(`Updated RF with plugin version ${pluginConfig.pluginVersion} and FPP version ${fppdVersion?.version}`);
      }
    })
    .catch(err => {
      log.error(err);
    });
  }
}

async function getPluginConfig() {
  await axios
  .get(`${url.fpp}/api/configfile/remote-falcon.json`, config)
  .then(res => {
    if(res.status === 200) {
      return res.data;
    }else {
      log.error(`Unable to get plugin config: ${res.status}`);
    }
  })
  .catch(err => {
    log.error(err);
  });
}

async function getFppdVersion() {
  await axios
  .get(`${url.fpp}/api/fppd/version`, config)
  .then(res => {
    if(res.status === 200) {
      return res.data;
    }else {
      log.error(`Unable to get FPPD version: ${res.status}`);
    }
  })
  .catch(err => {
    log.error(err);
  });
}