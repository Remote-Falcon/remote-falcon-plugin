const axios = require('axios');

sendPluginVersion();

async function sendPluginVersion() {
  const config = {
    headers: { remotetoken: pluginSettings.remoteToken }
  };
  const data = {};
  await axios
  .post('http://host.docker.internal:8080/remotefalcon/api/pluginVersion', data, config)
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