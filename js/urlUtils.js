const os = require("os");

async function getUrl() {
  var hostname = os.hostname();
  var url;
  if(hostname === 'localhost') {
    url = {
      api: 'http://host.docker.internal:8080/remotefalcon/api',
      fpp: 'http://localhost:8090'
    }
  }
  return url;
}

module.exports = { getUrl };