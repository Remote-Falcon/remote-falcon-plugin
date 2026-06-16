#!/usr/bin/env bash
# Tier 1 #2 — read-only connectivity test against the real RF API.
#
# Validates that the listener's cURL+TLS transport works against the
# production remotefalcon.com endpoints. Uses connectivity-probe.php
# which makes three sequential GET /remotePreferences calls (read-only,
# no state change to the user's RF account) and observes whether keep-
# alive is delivering a measurable speedup on warm calls.
#
# Why this matters: virtual smoke validates response decoding etc.
# against an HTTP mock on localhost, but we never validated cURL+TLS
# against real WAN infrastructure. This closes that gap with zero
# risk to RF state.
#
# Usage: FPP_HOST=192.168.1.80 ./tier1-connectivity.sh

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

section "Tier 1 #2 — real-RF connectivity probe (read-only)"

echo "Snapshotting Pi state..."
SNAP=$(pi_snapshot)

echo "Stopping current listener (avoids unrelated RF traffic during probe)..."
pi_stop_listener > /dev/null

echo "Installing perf branch..."
pi_install_branch "${TEST_BRANCH:-chore/hw-tier1-validation}" > /dev/null

# Restore the user's REAL settings (real token, real RF URL) for the probe
# so we hit production RF infrastructure as the listener would.
echo "Restoring real settings (real token + pluginsApiPath, listener stays stopped)..."
pi "sudo cp ${SNAP##*:} /home/fpp/media/config/plugin.remote-falcon
    sudo chown fpp:fpp /home/fpp/media/config/plugin.remote-falcon
    sudo chmod 664 /home/fpp/media/config/plugin.remote-falcon"

# Verify the listener is NOT running so the probe's calls are the only
# RF traffic during this test.
RUNNING=$(pi 'pgrep -f "/usr/bin/php.*remote_falcon_listener\.php" | head -1' || true)
if [ -n "$RUNNING" ]; then
    fail "listener is unexpectedly running (pid $RUNNING) — would generate RF traffic"
    pi_restore "$SNAP" > /dev/null
    summarize
    exit 1
fi
ok "listener confirmed stopped (no background RF traffic during probe)"

echo
echo "Running connectivity probe (3 sequential GET /remotePreferences via cURL keep-alive)..."
PROBE_OUT=$(pi 'sudo -u fpp php /home/fpp/media/plugins/remote-falcon/tests/hardware/connectivity-probe.php 2>&1')
PROBE_EXIT=$?
echo "$PROBE_OUT" | sed 's/^/  /'

if [ "$PROBE_EXIT" -eq 0 ]; then
    ok "probe exit 0 (all 3 RF calls succeeded against real infrastructure)"
else
    fail "probe failed (exit $PROBE_EXIT)"
fi

# Parse out the keep-alive observation
if echo "$PROBE_OUT" | grep -q "keep-alive observable"; then
    ok "keep-alive measurable on warm calls"
elif echo "$PROBE_OUT" | grep -q "small but consistent"; then
    ok "keep-alive working (speedup small — low-latency path)"
elif echo "$PROBE_OUT" | grep -q "transport works"; then
    ok "transport validated (warm/cold variance dominated by network jitter)"
else
    fail "couldn't classify probe output"
fi

echo
echo "Restoring Pi..."
pi_restore "$SNAP" > /dev/null
echo "  done"

summarize
