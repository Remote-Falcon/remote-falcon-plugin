const fs = require('fs');

const pluginConfigFile = '/home/fpp/media/config/remote-falcon.json';
const defaultPluginConfig = {
  requestListenerEnabled: true,
  remotePlaylist: "",
  interruptSchedule: false,
  remoteToken: "",
  requestFetchTime: 3,
  pluginVersion: "7.0.0",
  additionalWaitTime: 0
};

fs.open(pluginConfigFile,'r',function(err, fd){
  if (err) {
    fs.writeFile(pluginConfigFile, JSON.stringify(defaultPluginConfig), function(err) {});
  }
});