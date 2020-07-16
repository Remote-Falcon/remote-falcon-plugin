#!/bin/bash
REMOTE_FPP_ENABLED=$(tail /home/fpp/media/plugins/remote-falcon/remote_fpp_enabled.txt)
FPP_STATS_ENABLED=$(tail /home/fpp/media/plugins/remote-falcon/fpp_stats_enabled.txt)

/usr/bin/php /home/fpp/media/plugins/remote-falcon/plugin_version.php &

if [ "$REMOTE_FPP_ENABLED" = "true" ]; then
	/usr/bin/php /home/fpp/media/plugins/remote-falcon/remote_fpp.php &
fi
if [ "$FPP_STATS_ENABLED" = "true" ]; then
	/usr/bin/php /home/fpp/media/plugins/remote-falcon/fpp_stats.php &
fi
#postStart