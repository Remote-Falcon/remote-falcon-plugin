#!/bin/bash
REMOTE_FPP_ENABLED=$(tail /home/fpp/media/plugins/remote-falcon/remote_fpp_enabled.txt)

/usr/bin/php /home/fpp/media/plugins/remote-falcon/plugin_version.php &

if [ "$REMOTE_FPP_ENABLED" = "true" ]; then
	/usr/bin/php /home/fpp/media/plugins/remote-falcon/remote_fpp.php &
fi
#postStart