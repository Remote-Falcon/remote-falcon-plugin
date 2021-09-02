#!/bin/bash

chmod 777 -R /home/fpp/media/plugins/remote-falcon/commands

/usr/bin/php /home/fpp/media/plugins/remote-falcon/plugin_version.php &
/usr/bin/php /home/fpp/media/plugins/remote-falcon/remote_fpp.php &

#postStart


