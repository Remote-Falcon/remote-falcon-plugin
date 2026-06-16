#!/bin/bash

# Cleanly stop the listener and remove the Apache CSP entry that fpp_install.sh added.
# FPP's plugin manager removes the plugin directory itself after this script exits;
# we should only undo our system-level side effects here.

. ${FPPDIR}/scripts/common 2>/dev/null || true

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

if [ -x "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" ]; then
    ${FPPDIR}/scripts/ManageApacheContentPolicy.sh remove connect-src https://remotefalcon.com 2>/dev/null || true
fi

# Remove the logrotate symlink installed by fpp_install.sh.
sudo rm -f /etc/logrotate.d/remote-falcon 2>/dev/null || rm -f /etc/logrotate.d/remote-falcon 2>/dev/null || true

#fpp_uninstall
