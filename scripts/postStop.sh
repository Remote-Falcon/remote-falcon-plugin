#!/bin/bash

# Stop the Remote Falcon listener cleanly.
# Reads the PID written by postStart.sh, sends SIGTERM, waits, then SIGKILL if needed.

PIDFILE=/home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid

if [ -f "$PIDFILE" ]; then
    PID=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        kill -TERM "$PID" 2>/dev/null || true
        for i in 1 2 3 4 5; do
            kill -0 "$PID" 2>/dev/null || break
            sleep 1
        done
        kill -KILL "$PID" 2>/dev/null || true
    fi
    rm -f "$PIDFILE"
else
    pkill -f remote_falcon_listener 2>/dev/null || true
fi

#postStop
