#!/bin/bash

# Stop the Remote Falcon listener cleanly.
# Reads the PID written by postStart.sh, sends SIGTERM, waits, then SIGKILL if needed.
# Lifecycle hooks are DOCUMENTED to run as root (per FPP's plugin guidelines),
# but don't assume it. When they don't, signalling a listener owned by another
# user fails with EPERM, which `kill -0` reports identically to "no such
# process" — so the listener survives AND its pidfile is removed, orphaning it.

: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common" 2>/dev/null || true
: "${MEDIADIR:=/home/fpp/media}"

PIDFILE="${MEDIADIR}/plugins/remote-falcon/remote_falcon_listener.pid"

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

if [ -f "$PIDFILE" ]; then
    PID=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$PID" ] && rf_pid_alive "$PID"; then
        rf_signal_pid "$PID" TERM || true
        for i in 1 2 3 4 5; do
            rf_pid_alive "$PID" || break
            sleep 1
        done
        if rf_pid_alive "$PID"; then
            rf_signal_pid "$PID" KILL || true
            sleep 1
        fi
    fi
    if [ -n "$PID" ] && rf_pid_alive "$PID"; then
        # Keep the pidfile: it's the only handle on a listener we couldn't
        # stop. Sweep any stragglers by name as a last resort.
        echo "Remote Falcon: WARNING - listener PID $PID could not be stopped; keeping $PIDFILE" >&2
        pkill -f remote_falcon_listener 2>/dev/null || true
    else
        rm -f "$PIDFILE"
    fi
else
    pkill -f remote_falcon_listener 2>/dev/null || true
fi

#postStop
