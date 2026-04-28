#!/bin/bash

# Launch the Remote Falcon listener as a detached background process.
# A PID file is written so postStop.sh / fpp_uninstall.sh can shut it down cleanly.

PLUGINDIR=/home/fpp/media/plugins/remote-falcon
PIDFILE=${PLUGINDIR}/remote_falcon_listener.pid
LOGFILE=/home/fpp/media/logs/remote-falcon-listener.log

# Clean up a stale PID file from a previous run that no longer exists.
if [ -f "$PIDFILE" ]; then
    OLDPID=$(cat "$PIDFILE" 2>/dev/null)
    if [ -z "$OLDPID" ] || ! kill -0 "$OLDPID" 2>/dev/null; then
        rm -f "$PIDFILE"
    fi
fi

# Don't start a second listener if one is already running.
if [ -f "$PIDFILE" ]; then
    exit 0
fi

nohup /usr/bin/php ${PLUGINDIR}/remote_falcon_listener.php >> "$LOGFILE" 2>&1 < /dev/null &
echo $! > "$PIDFILE"

#postStart
