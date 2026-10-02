#!/bin/bash
# Tier 1 #5 — Show Token gate and CSP cleanup on real FPP (issue-tracker #194).
#
#   1. Upgrade (fpp_install.sh re-run as root, as FPP's plugin manager does)
#      removes the legacy connect-src https://remotefalcon.com CSP entry.
#   2. With no Show Token, nothing reaches remotefalcon.com: after a listener
#      restart and after a reboot, captured with tcpdump on the Pi.
#   3. With the real token back, a Restart Listener (what the UI runs after a
#      token save) reconnects with no fppd restart.
#
# Requires the Pi's real RF settings on disk (real token, real pluginsApiPath)
# for step 3, and REBOOTS the Pi in step 2 (skip with SKIP_REBOOT=1). Results
# are strongest with a show playing: the old listener posted now-playing
# without a token.
#
# Usage: FPP_HOST=192.168.1.80 TEST_BRANCH=release/2026.10.02 ./tier1-token-gate.sh

set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
. "$HERE/lib.sh"

BRANCH="${TEST_BRANCH:?TEST_BRANCH is required (the branch to install)}"
CFG=/home/fpp/media/config/plugin.remote-falcon
LOG=/home/fpp/media/logs/plugin-remote-falcon.log
CSP_JSON=/home/fpp/media/config/csp_allowed_domains.json
CSP_SCRIPT=/opt/fpp/scripts/ManageApacheContentPolicy.sh
RESTART_URL='http://127.0.0.1/api/command/Remote%20Falcon%20-%20Restart%20Listener'
WINDOW=45   # > the listener's 30s heartbeat interval

section "Tier 1 #5 — token gate + CSP cleanup (branch $BRANCH)"

HAD_CSP=$(pi "grep -c remotefalcon.com $CSP_JSON 2>/dev/null || echo 0" | tail -1)
SNAP=$(pi_snapshot | tail -1)
# pi_snapshot writes to /tmp, which the reboot in step 2 clears. Move it
# somewhere persistent so the restore (and step 3's real settings) survive.
SNAP_DIR=/home/fpp/rf-hw-snap
pi "sudo mkdir -p $SNAP_DIR && sudo mv ${SNAP%%:*} ${SNAP##*:} $SNAP_DIR/"
SNAP="$SNAP_DIR/$(basename "${SNAP%%:*}"):$SNAP_DIR/$(basename "${SNAP##*:}")"
echo "Snapshot: $SNAP (CSP entry present before test: $HAD_CSP)"
restore() {
    pi_restore "$SNAP" || return 1
    # The snapshot covers the plugin tree and settings only; put back the
    # CSP entry if the Pi had it, so it ends where it started.
    if [ "$HAD_CSP" != "0" ]; then
        pi "sudo $CSP_SCRIPT add connect-src https://remotefalcon.com > /dev/null 2>&1 || true"
    fi
}
trap restore EXIT

# Packets sent to remotefalcon.com:443 during $WINDOW seconds after running
# the command in $1 on the Pi. Resolves the host at test time (Cloudflare,
# v4 + v6) the same way the listener's resolver does.
rf_packets_during() {
    local action="$1"
    pi "HOSTS=\$(getent ahosts remotefalcon.com | awk '{print \$1}' | sort -u | sed 's/^/host /' | paste -sd' ' | sed 's/ host / or host /g')
        sudo rm -f /tmp/rf-cap.txt
        sudo timeout $((WINDOW + 3)) tcpdump -nn -i any -l \"dst port 443 and (\$HOSTS)\" > /tmp/rf-cap.txt 2>/dev/null &
        sleep 2
        $action
        sleep $WINDOW
        grep -c ' > ' /tmp/rf-cap.txt || true" | tail -1
}
# Print the captured packets (for a failure message).
rf_packets_show() { pi "grep ' > ' /tmp/rf-cap.txt | head -5"; }

pi_install_branch "$BRANCH" > /dev/null

section "1. upgrade removes the legacy CSP entry"
pi "sudo $CSP_SCRIPT add connect-src https://remotefalcon.com > /dev/null 2>&1"
pi "sudo FPPDIR=/opt/fpp bash /home/fpp/media/plugins/remote-falcon/scripts/fpp_install.sh > /dev/null 2>&1"
if [ "$(pi "grep -c remotefalcon.com $CSP_JSON || true" | tail -1)" = "0" ]; then
    ok "fpp_install.sh (as root) removed connect-src https://remotefalcon.com"
else
    fail "CSP entry still present after fpp_install.sh"
fi

section "2. no Show Token: nothing reaches remotefalcon.com"
STATUS=$(pi "curl -s http://127.0.0.1/api/fppd/status | python3 -c 'import json,sys; print(json.load(sys.stdin).get(\"status_name\"))'" | tail -1)
echo "  FPP status: $STATUS"
# Stop the listener first so a process started before the token was cleared
# can't send during the capture window; the restart below is then the only
# process start.
pi_stop_listener > /dev/null
pi "sudo sed -i 's/^remoteToken = .*/remoteToken = \"\"/' $CFG && sudo truncate -s 0 $LOG"

N=$(rf_packets_during "curl -s -o /dev/null '$RESTART_URL'")
if [ "$N" = "0" ]; then
    ok "0 packets to remotefalcon.com in ${WINDOW}s after Restart Listener (FPP $STATUS)"
else
    fail "$N packets to remotefalcon.com with no token after Restart Listener"
    rf_packets_show
fi
if pi "grep -q 'No Show Token set' $LOG"; then
    ok "listener logs that it is idle without a token"
else
    fail "no 'No Show Token set' line in the plugin log"
fi

if [ "${SKIP_REBOOT:-0}" != "1" ]; then
    echo "  rebooting $FPP_HOST..."
    pi "sudo truncate -s 0 $LOG; (sleep 1; sudo reboot) > /dev/null 2>&1 &" || true
    sleep 20
    for _ in $(seq 1 60); do
        pi "curl -sf http://127.0.0.1/api/fppd/status > /dev/null" 2>/dev/null && break
        sleep 5
    done
    sleep 5
    N=$(rf_packets_during "true")
    if [ "$N" = "0" ]; then
        ok "0 packets to remotefalcon.com in ${WINDOW}s after reboot"
    else
        fail "$N packets to remotefalcon.com with no token after reboot"
        rf_packets_show
    fi
    if pi "grep -q 'No Show Token set' $LOG && ! grep -q 'Unable to fetch remote preferences' $LOG"; then
        ok "listener started at boot idle, with no preferences fetch"
    else
        fail "boot log shows a preferences fetch or no idle line"
    fi
fi

section "3. real token restored: Restart Listener reconnects"
TAR_CFG="${SNAP##*:}"
if ! pi "sudo test -f $TAR_CFG"; then
    fail "snapshot settings $TAR_CFG missing; cannot restore the real token"
    summarize
    exit 1
fi
pi "sudo cp $TAR_CFG $CFG && sudo chown fpp:fpp $CFG && sudo truncate -s 0 $LOG"
N=$(rf_packets_during "curl -s -o /dev/null '$RESTART_URL'")
if [ "$N" -gt 0 ] 2>/dev/null; then
    ok "$N packets to remotefalcon.com after Restart Listener with a token"
else
    fail "no traffic to remotefalcon.com after restoring the token"
fi
if pi "grep -q 'Viewer Control Mode:' $LOG"; then
    ok "listener fetched viewer control mode with the real token"
else
    fail "no 'Viewer Control Mode:' line after restoring the token"
fi

summarize
