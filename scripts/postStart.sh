#!/bin/bash
 . /opt/fpp/scripts/common
CFGFILE= /home/fpp/media/config/plugin.remote-falcon
REMOTE_FPP_ENABLED=$(getSetting "remote_fpp_enabled")

if [ "$REMOTE_FPP_ENABLED" = "true" ]; then
	/usr/bin/php /home/fpp/media/plugins/remote-falcon/remote_fpp.php &
fi
#postStart
