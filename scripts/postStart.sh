#!/bin/bash
node /home/fpp/media/plugins/remote-falcon/js/sendPluginVersion.js &
node /home/fpp/media/plugins/remote-falcon/js/requestListener.js &
#sh /home/fpp/media/plugins/remote-falcon/scripts/postStart.sh
#post_start