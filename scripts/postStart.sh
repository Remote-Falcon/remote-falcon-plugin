#!/bin/bash

# Launch the Remote Falcon listener as a detached background process.
# A PID file is written so postStop.sh / fpp_uninstall.sh can shut it down cleanly.

# Resolve FPP's media/logs directories the FPP-provided way (a relocated
# media root changes them), with defaults in case common can't be sourced.
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common" 2>/dev/null || true
: "${MEDIADIR:=/home/fpp/media}"
: "${LOGDIR:=${MEDIADIR}/logs}"

PLUGINDIR="${MEDIADIR}/plugins/remote-falcon"
PIDFILE="${PLUGINDIR}/remote_falcon_listener.pid"
LOGFILE="${LOGDIR}/plugin-remote-falcon.log"

# One-time migration from the pre-FPP-10 log name. Carry the active log's
# contents over so the rename doesn't discard recent history; only remove
# the old files once the copy succeeded, so a failed copy retries next start.
OLDLOG="${LOGDIR}/remote-falcon-listener.log"
if [ -f "$OLDLOG" ]; then
    if cat "$OLDLOG" >> "$LOGFILE" 2>/dev/null; then
        rm -f "$OLDLOG" "$OLDLOG".* 2>/dev/null
    fi
fi

# FPP rotates every log in its logs directory itself (etc/logrotate.d/
# fpp_other_logs), so the plugin-owned logrotate config earlier versions
# installed double-rotates the same file with a conflicting policy. Remove
# the legacy config on upgraded installs — fpp_uninstall.sh only runs on
# uninstall, so this cleanup has to live here.
rm -f /etc/logrotate.d/remote-falcon 2>/dev/null || true

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

nohup /usr/bin/php "${PLUGINDIR}/remote_falcon_listener.php" >> "$LOGFILE" 2>&1 < /dev/null &
echo $! > "$PIDFILE"

#postStart
