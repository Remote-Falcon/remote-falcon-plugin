#!/bin/bash
REMOTE_FPP_ENABLED=$(tail /home/fpp/media/plugins/remote-falcon/remote_fpp_enabled.txt)

/usr/bin/php /home/fpp/media/plugins/remote-falcon/remote_playlist_sync.php &

if [ "$REMOTE_FPP_ENABLED" = "true" ]; then
	sh /home/fpp/media/plugins/remote-falcon/scripts/remote_fpp.sh &
fi
#postStart