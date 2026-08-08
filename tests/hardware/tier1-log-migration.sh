#!/usr/bin/env bash
# Tier 1 — log rename migration + legacy logrotate cleanup (2026.08.08 release).
#
# Validates the postStart.sh migration path shipped with the plugin-remote-falcon.log
# rename: old log contents carried over, old rotated archives removed, legacy
# /etc/logrotate.d/remote-falcon removed, listener healthy on the new log.
# postStart/postStop run as ROOT here, matching how FPP invokes hooks
# (fppd.service ExecStartPre/Post and the web UI's $SUDO calls).
#
# Usage: FPP_HOST=192.168.1.80 [TEST_BRANCH=release/2026.08.08] ./tier1-log-migration.sh

set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
source "$HERE/lib.sh"

BRANCH="${TEST_BRANCH:-release/2026.08.08}"
OLDLOG=/home/fpp/media/logs/remote-falcon-listener.log
NEWLOG=/home/fpp/media/logs/plugin-remote-falcon.log

section "Tier 1 #4 — log rename migration (branch $BRANCH)"

echo "Snapshotting Pi state..."
SNAP=$(pi_snapshot | tail -1)
echo "Stopping current listener..."
pi_stop_listener > /dev/null
echo "Installing $BRANCH..."
pi_install_branch "$BRANCH" > /dev/null
pi_seed_safe_settings > /dev/null

echo "Seeding legacy artifacts (old log + rotated archives + logrotate config)..."
pi "sudo rm -f $NEWLOG
    echo 'OLD-LOG-MARKER-LINE' | sudo tee $OLDLOG > /dev/null
    echo 'rotated-1' | sudo tee $OLDLOG.1 > /dev/null
    echo 'rotated-2' | sudo gzip -c | sudo tee $OLDLOG.2.gz > /dev/null
    sudo chown fpp:fpp $OLDLOG $OLDLOG.1 $OLDLOG.2.gz
    echo '# legacy config' | sudo tee /etc/logrotate.d/remote-falcon > /dev/null" > /dev/null

echo "Running postStart.sh as root (as FPP does)..."
pi 'sudo /home/fpp/media/plugins/remote-falcon/scripts/postStart.sh > /dev/null 2>&1
    sleep 3' > /dev/null

section "Assertions"

if pi "grep -q 'OLD-LOG-MARKER-LINE' $NEWLOG && echo yes || echo no" | grep -q yes; then
    ok "old log contents migrated into $NEWLOG"
else
    fail "old log contents NOT found in $NEWLOG"
fi

if pi "test -f $OLDLOG && echo present || echo gone" | grep -q gone; then
    ok "old active log removed"
else
    fail "old active log still present"
fi

if pi "ls $OLDLOG.* 2>/dev/null | wc -l" | grep -q '^0$'; then
    ok "old rotated archives removed"
else
    fail "old rotated archives still present"
fi

if pi "test -f /etc/logrotate.d/remote-falcon && echo present || echo gone" | grep -q gone; then
    ok "legacy /etc/logrotate.d/remote-falcon removed"
else
    fail "legacy logrotate config still present"
fi

PID=$(pi_listener_pid)
if [ -n "$PID" ] && pi_listener_alive "$PID" | grep -q alive; then
    ok "listener running (pid $PID) after root postStart"
else
    fail "listener not running after root postStart"
fi

if pi "grep -q 'Starting Remote Falcon Plugin' $NEWLOG && echo yes || echo no" | grep -q yes; then
    ok "startup banner present in new log"
else
    fail "no startup banner in new log"
fi

echo "Running postStart.sh again (idempotency — migration must not re-fire)..."
pi 'sudo /home/fpp/media/plugins/remote-falcon/scripts/postStart.sh > /dev/null 2>&1
    sleep 1' > /dev/null
PID2=$(pi_listener_pid)
if [ "$PID2" = "$PID" ]; then
    ok "second postStart is a no-op (same pid $PID)"
else
    fail "second postStart changed pid ($PID -> $PID2)"
fi

echo "Running postStop.sh as root (no-sudo kill path)..."
pi 'sudo /home/fpp/media/plugins/remote-falcon/scripts/postStop.sh > /dev/null 2>&1
    sleep 1' > /dev/null
if [ -n "$PID" ] && pi_listener_alive "$PID" | grep -q dead; then
    ok "root postStop killed root-spawned listener without sudo fallback"
else
    fail "listener survived postStop"
fi

echo "Restoring Pi..."
pi_restore "$SNAP" > /dev/null
echo "  done"

summarize
