#!/bin/bash

# Ensure no previous listener is still running before postStart.sh launches a new one.
# Lifecycle hooks run as root (per FPP's plugin guidelines), so a plain kill
# reaches the listener regardless of which user originally spawned it.

: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common" 2>/dev/null || true
: "${MEDIADIR:=/home/fpp/media}"

PLUGINDIR="${MEDIADIR}/plugins/remote-falcon"
PIDFILE="${PLUGINDIR}/remote_falcon_listener.pid"

# FPPD execs command scripts directly, so a command file that lost its
# executable bit (zip install, cp, or a 644 blob slipping into git — bit us
# on set_active_viewer_page.php in the 2026.07.16 cycle) fails with a silent
# "Permission denied" in fppd.log while the FPP UI still reports "complete".
# Normalize on every start; _lib.php is an include, not an entry point, but
# +x on it is harmless.
chmod +x "${PLUGINDIR}"/commands/*.php 2>/dev/null || true

if [ -f "$PIDFILE" ]; then
    OLDPID=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$OLDPID" ] && kill -0 "$OLDPID" 2>/dev/null; then
        kill -TERM "$OLDPID" 2>/dev/null || true
        for i in 1 2 3; do
            kill -0 "$OLDPID" 2>/dev/null || break
            sleep 1
        done
        if kill -0 "$OLDPID" 2>/dev/null; then
            kill -KILL "$OLDPID" 2>/dev/null || true
        fi
    fi
    rm -f "$PIDFILE"
fi

#preStart
