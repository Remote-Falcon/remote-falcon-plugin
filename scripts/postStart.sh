#!/bin/bash
node /home/fpp/media/plugins/remote-falcon/js/requestListener.js &
node /home/fpp/media/plugins/remote-falcon/js/sendPluginVersion.js &
#post_start