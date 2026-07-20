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

# FPPD execs command scripts directly, so a command file that lost its
# executable bit (zip install, cp, or a 644 blob slipping into git — bit us
# on set_active_viewer_page.php in the 2026.07.16 cycle) fails with a silent
# "Permission denied" in fppd.log while the FPP UI still reports "complete".
# Normalize on every start; _lib.php is an include, not an entry point, but
# +x on it is harmless.
chmod +x /home/fpp/media/plugins/remote-falcon/commands/*.php 2>/dev/null || true

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
