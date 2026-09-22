#!/bin/bash

# Ensure no previous listener is still running before postStart.sh launches a new one.
# Lifecycle hooks are DOCUMENTED to run as root (per FPP's plugin guidelines),
# but don't assume it. When they don't, signalling a listener owned by another
# user fails with EPERM, which `kill -0` reports identically to "no such
# process" — so the old listener is left alive AND its pidfile removed,
# orphaning it. Every later start then adds another, each polling and logging
# independently.

: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common" 2>/dev/null || true
: "${MEDIADIR:=/home/fpp/media}"

PLUGINDIR="${MEDIADIR}/plugins/remote-falcon"
PIDFILE="${PLUGINDIR}/remote_falcon_listener.pid"

# /proc is authoritative on Linux and, unlike `kill -0`, never conflates
# "no such process" with "not permitted to signal it".
rf_pid_alive() {
    [ -d "/proc/$1" ]
}

# Signal a pid, falling back to non-interactive sudo when we lack permission.
rf_signal_pid() {
    kill "-$2" "$1" 2>/dev/null && return 0
    command -v sudo >/dev/null 2>&1 && sudo -n kill "-$2" "$1" 2>/dev/null
}

# FPPD execs command scripts directly, so a command file that lost its
# executable bit (zip install, cp, or a 644 blob slipping into git — bit us
# on set_active_viewer_page.php in the 2026.07.16 cycle) fails with a silent
# "Permission denied" in fppd.log while the FPP UI still reports "complete".
# Normalize on every start; _lib.php is an include, not an entry point, but
# +x on it is harmless.
chmod +x "${PLUGINDIR}"/commands/*.php 2>/dev/null || true

if [ -f "$PIDFILE" ]; then
    OLDPID=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$OLDPID" ] && rf_pid_alive "$OLDPID"; then
        rf_signal_pid "$OLDPID" TERM || true
        for i in 1 2 3; do
            rf_pid_alive "$OLDPID" || break
            sleep 1
        done
        if rf_pid_alive "$OLDPID"; then
            rf_signal_pid "$OLDPID" KILL || true
            sleep 1
        fi
    fi
    # Only drop the pidfile once the process is actually gone. Removing it
    # while the listener still runs loses the only handle anything has on it.
    if [ -n "$OLDPID" ] && rf_pid_alive "$OLDPID"; then
        echo "Remote Falcon: WARNING - listener PID $OLDPID is still running and could not be stopped; keeping $PIDFILE so it can be cleaned up rather than orphaned" >&2
    else
        rm -f "$PIDFILE"
    fi
fi

#preStart
