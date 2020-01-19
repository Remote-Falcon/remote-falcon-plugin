#!/bin/bash

REMOTE_FPP_ENABLED=$(tail /home/fpp/media/plugins/remote-falcon/remote_fpp_enabled.txt)
REMOTE_JUKEBOX_ENABLED=$(tail /home/fpp/media/plugins/remote-falcon/remote_jukebox_enabled.txt)

if [ "$REMOTE_FPP_ENABLED" = "true" ]; then
	#/usr/bin/curl "http://fpp/runEventScript.php?scriptName=fpp_remote.sh" &
	sh /home/fpp/media/plugins/remote-falcon/scripts/fpp_remote.sh &
	/usr/bin/php /home/fpp/media/plugins/remote-falcon/fpp_remote.php &
fi
if [ "$REMOTE_JUKEBOX_ENABLED" = "true" ]; then
	#/usr/bin/curl "http://fpp/runEventScript.php?scriptName=remote_jukebox.sh" &
	sh /home/fpp/media/plugins/remote-falcon/scripts/remote_jukebox.sh &
fi

