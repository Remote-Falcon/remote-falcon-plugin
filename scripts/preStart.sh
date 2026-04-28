#!/bin/bash

# Ensure no previous listener is still running before postStart.sh launches a new one.

PIDFILE=/home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid

if [ -f "$PIDFILE" ]; then
    OLDPID=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$OLDPID" ] && kill -0 "$OLDPID" 2>/dev/null; then
        kill -TERM "$OLDPID" 2>/dev/null || true
        for i in 1 2 3; do
            kill -0 "$OLDPID" 2>/dev/null || break
            sleep 1
        done
        kill -KILL "$OLDPID" 2>/dev/null || true
    fi
    rm -f "$PIDFILE"
fi

#preStart
