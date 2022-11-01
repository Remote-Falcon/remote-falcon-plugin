#!/bin/bash
uname=$(uname -m)
validArch=1
if [[ "$uname" == *"armv7"* ]]; then
  echo "Configuring node for ARMv7. Be patient, this can take a bit..."
  wget https://nodejs.org/dist/v16.14.2/node-v16.14.2-linux-armv7l.tar.gz
  tar -xzf node-v16.14.2-linux-armv7l.tar.gz
  sudo cp -R node-v16.14.2-linux-armv7l/* /usr/local/
elif [[ "$uname" == *"armv8"* ]]; then
  echo "Configuring node for AMRv8. Be patient, this can take a bit..."
  wget https://nodejs.org/dist/v16.14.2/node-v16.14.2-linux-arm64.tar.gz
  tar -xzf node-v16.14.2-linux-arm64.tar.gz
  sudo cp -R node-v16.14.2-linux-arm64/* /usr/local/
elif [[ "$uname" == *"x86_64"* ]]; then
  echo "Configuring node for x86_64. Be patient, this can take a bit..."
  wget https://nodejs.org/dist/v16.14.2/node-v16.14.2-linux-x64.tar.gz
  tar -xzf node-v16.14.2-linux-x64.tar.gz
  sudo cp -R node-v16.14.2-linux-x64/* /usr/local/
else
  $validArch=0
  echo "Looks like the below architecture is not yet supported."
  echo $(uname -m)
fi
if [ $validArch -eq 1 ]; then
  echo $(node -v)
  echo "Installing Dependencies"
  npm install --prefix /home/fpp/media/plugins/remote-falcon
  echo "First time setup"
  node /home/fpp/media/plugins/remote-falcon/js/pluginSetup.js
  echo "All done!"
fi
#sh /home/fpp/media/plugins/remote-falcon/scripts/fpp_install.sh
#fpp_install