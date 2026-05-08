#!/bin/bash

# Stop the Remote Falcon listener cleanly.
# Reads the PID written by postStart.sh, sends SIGTERM, waits, then SIGKILL if needed.
#
# Cross-user safety: the listener may have been started by a different user
# than the one running this script. Specifically, FPP's command system runs
# scripts as root (inherited from FPPD), so the listener can be root-owned
# while postStop is invoked as the fpp user. Direct kill from fpp to a
# root-owned process returns EPERM. We try direct first, then fall back to
# `sudo -n` (passwordless), so the script works regardless of which user
# spawned the listener.

PIDFILE=/home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid

# Send a signal to PID; try directly first, fall back to sudo if we can't
# signal due to permissions. Tolerates failure of both paths.
rf_signal() {
    local sig="$1"
    local pid="$2"
    kill -"$sig" "$pid" 2>/dev/null || sudo -n kill -"$sig" "$pid" 2>/dev/null || true
}

# Is the process alive (regardless of who owns it)?
rf_alive() {
    kill -0 "$1" 2>/dev/null || sudo -n kill -0 "$1" 2>/dev/null
}

if [ -f "$PIDFILE" ]; then
    PID=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$PID" ] && rf_alive "$PID"; then
        rf_signal TERM "$PID"
        for i in 1 2 3 4 5; do
            rf_alive "$PID" || break
            sleep 1
        done
        if rf_alive "$PID"; then
            rf_signal KILL "$PID"
        fi
    fi
    rm -f "$PIDFILE"
else
    pkill -f remote_falcon_listener 2>/dev/null || sudo -n pkill -f remote_falcon_listener 2>/dev/null || true
fi

#postStop
