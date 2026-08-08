#!/bin/bash

# Launch the Remote Falcon listener as a detached background process.
# A PID file is written so postStop.sh / fpp_uninstall.sh can shut it down cleanly.

PLUGINDIR=/home/fpp/media/plugins/remote-falcon
PIDFILE=${PLUGINDIR}/remote_falcon_listener.pid
LOGFILE=/home/fpp/media/logs/plugin-remote-falcon.log

# One-time migration from the pre-FPP-10 log name. Carry the active log's
# contents over so the rename doesn't discard recent history; only remove
# the old files once the copy succeeded, so a failed copy retries next start.
OLDLOG=/home/fpp/media/logs/remote-falcon-listener.log
if [ -f "$OLDLOG" ]; then
    if cat "$OLDLOG" >> "$LOGFILE" 2>/dev/null; then
        rm -f "$OLDLOG" "$OLDLOG".* 2>/dev/null
    fi
fi

# Keep the logrotate config current. This runs on every plugin start (unlike
# fpp_install.sh, which FPP runs only on first install), so policy changes
# and the log rename reach upgraded installs too. Copy (not symlink) and
# force root ownership — logrotate refuses to process configs whose target
# file is owned by a non-root user, and the plugin tree is fpp-owned. FPP
# doesn't always run hooks as root, so fall back to passwordless sudo.
LOGROTATE_SRC="${PLUGINDIR}/scripts/logrotate.d-remote-falcon"
LOGROTATE_DST=/etc/logrotate.d/remote-falcon
if [ -f "$LOGROTATE_SRC" ] && [ -d /etc/logrotate.d ] && ! cmp -s "$LOGROTATE_SRC" "$LOGROTATE_DST"; then
    install -m 0644 -o root -g root "$LOGROTATE_SRC" "$LOGROTATE_DST" 2>/dev/null || \
        sudo -n install -m 0644 -o root -g root "$LOGROTATE_SRC" "$LOGROTATE_DST" 2>/dev/null || true
fi

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

nohup /usr/bin/php ${PLUGINDIR}/remote_falcon_listener.php >> "$LOGFILE" 2>&1 < /dev/null &
echo $! > "$PIDFILE"

#postStart
