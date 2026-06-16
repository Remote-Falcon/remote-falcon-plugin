#!/bin/bash

# Ensure no previous listener is still running before postStart.sh launches a new one.
#
# Same cross-user kill story as postStop.sh: an old listener may be owned
# by a different user than the one running preStart (FPP command system
# runs as root; manual SSH may run as fpp). Try direct kill first, fall
# back to passwordless sudo so we don't leave orphans.

PIDFILE=/home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid

rf_signal() {
    local sig="$1"
    local pid="$2"
    kill -"$sig" "$pid" 2>/dev/null || sudo -n kill -"$sig" "$pid" 2>/dev/null || true
}

rf_alive() {
    kill -0 "$1" 2>/dev/null || sudo -n kill -0 "$1" 2>/dev/null
}

if [ -f "$PIDFILE" ]; then
    OLDPID=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$OLDPID" ] && rf_alive "$OLDPID"; then
        rf_signal TERM "$OLDPID"
        for i in 1 2 3; do
            rf_alive "$OLDPID" || break
            sleep 1
        done
        if rf_alive "$OLDPID"; then
            rf_signal KILL "$OLDPID"
        fi
    fi
    rm -f "$PIDFILE"
fi

#preStart
