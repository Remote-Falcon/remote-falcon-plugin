#!/bin/bash
# In-container plugin smoke test. Runs inside an FPP docker container after
# the matrix orchestrator has already done:
#   - copy plugin source to /home/fpp/media/plugins/remote-falcon
#   - chown fpp:fpp the tree
#   - run scripts/fpp_install.sh
#
# This script then verifies plugin behavior without going through SSH or
# real hardware: command discovery, settings round-trip, listener startup,
# Restart Listener via /api/command. Designed to surface FPP-version-
# specific incompatibilities (route changes, deprecated PHP APIs, common.php
# behavior drift) as early as possible.
#
# Each check is independent — we don't bail on first failure; we count
# pass/fail and exit nonzero if any failed. This lets one matrix run see
# every problem on a given FPP version, not just the first one.
#
# Output goes to stdout; the orchestrator captures it.

set -uo pipefail

PASS=0
FAIL=0

ok()   { echo "  PASS  $1"; PASS=$((PASS+1)); }
fail() { echo "  FAIL  $1"; FAIL=$((FAIL+1)); }

PLUGIN_DIR=/home/fpp/media/plugins/remote-falcon
CONFIG_FILE=/home/fpp/media/config/plugin.remote-falcon
LISTENER_LOG=/home/fpp/media/logs/remote-falcon-listener.log
PID_FILE=$PLUGIN_DIR/remote_falcon_listener.pid

echo "=== environment ==="
echo "  PHP: $(php --version 2>&1 | head -1)"
echo "  FPP version: $(curl -sf http://127.0.0.1/api/fppversion 2>/dev/null || curl -sf http://127.0.0.1/api/system/status 2>/dev/null | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('version','?'))" 2>/dev/null || echo unknown)"

# --- Check 1: plugin tree exists and the install script ran ---
echo
echo "=== plugin install ==="
if [ -f $PLUGIN_DIR/remote_falcon_listener.php ]; then
    ok "plugin tree present at $PLUGIN_DIR"
else
    fail "plugin tree missing — fpp_install.sh likely failed"
    exit 1
fi

if [ -f $PLUGIN_DIR/scripts/postStart.sh ] && [ -x $PLUGIN_DIR/scripts/postStart.sh ]; then
    ok "lifecycle scripts present and executable"
else
    fail "lifecycle scripts missing or not executable"
fi

# --- Check 2: command discovery ---
echo
echo "=== command discovery ==="
COMMANDS=$(curl -sf http://127.0.0.1/api/commands 2>/dev/null || echo "")
EXPECTED_CMDS=(
    "Remote Falcon - Restart Listener"
    "Remote Falcon - Stop Listener"
    "Remote Falcon - Turn Interrupt Schedule On"
    "Remote Falcon - Turn Interrupt Schedule Off"
    "Remote Falcon - Turn Viewer Control On"
    "Remote Falcon - Turn Viewer Control Off"
    "Remote Falcon - Turn Managed PSA On"
    "Remote Falcon - Turn Managed PSA Off"
    "Remote Falcon - Purge Queue/Reset Votes"
    "Remote Falcon - Update Remote Playlist"
)
for name in "${EXPECTED_CMDS[@]}"; do
    if echo "$COMMANDS" | grep -qF "$name"; then
        ok "registered: $name"
    else
        fail "NOT registered: $name"
    fi
done

# --- Check 3: settings round-trip via FPP's /api/plugin/.../settings ---
echo
echo "=== settings round-trip ==="
# Seed a baseline so the file exists with the right ownership.
cat > $CONFIG_FILE <<'CFG'
pluginVersion = "smoke"
remotePlaylist = "RF-Test"
interruptSchedule = "false"
remoteToken = ""
requestFetchTime = "3"
additionalWaitTime = "0"
fppStatusCheckTime = "1"
pluginsApiPath = "http://127.0.0.1:9999"
verboseLogging = "false"
remoteFalconListenerEnabled = "true"
remoteFalconListenerRestarting = "false"
init = "true"
autoSyncMetadata = "false"
CFG
chown fpp:fpp $CONFIG_FILE
chmod 664 $CONFIG_FILE

POST_RESP=$(curl -sf -X POST -H 'Content-Type: text/plain' --data 'true' \
    http://127.0.0.1/api/plugin/remote-falcon/settings/interruptSchedule 2>&1 || echo CURL_FAIL)
if echo "$POST_RESP" | grep -q '"status":"OK"'; then
    ok "POST settings/interruptSchedule -> status:OK"
else
    fail "POST settings did not return OK (resp: $(echo "$POST_RESP" | head -c 120))"
fi

GET_RESP=$(curl -sf http://127.0.0.1/api/plugin/remote-falcon/settings/interruptSchedule 2>/dev/null)
if echo "$GET_RESP" | grep -qE '"interruptSchedule":"true"'; then
    ok "GET round-trip returned the value we POSTed"
else
    fail "GET round-trip mismatch (resp: $(echo "$GET_RESP" | head -c 120))"
fi

# Reset for clean listener start
sed -i 's/^interruptSchedule = .*/interruptSchedule = "false"/' $CONFIG_FILE

# --- Check 4: listener starts via postStart.sh ---
echo
echo "=== listener boot ==="
mkdir -p /home/fpp/media/logs
truncate -s 0 $LISTENER_LOG 2>/dev/null || true
chown fpp:fpp $LISTENER_LOG 2>/dev/null || true
sudo -u fpp $PLUGIN_DIR/scripts/postStart.sh > /dev/null 2>&1 || \
    bash $PLUGIN_DIR/scripts/postStart.sh > /dev/null 2>&1
sleep 3

LPID=$(cat $PID_FILE 2>/dev/null || echo "")
if [ -n "$LPID" ] && [ -d "/proc/$LPID" ]; then
    ok "listener started (pid $LPID)"
else
    fail "listener did not start (pid file: $LPID)"
fi

if grep -q "Starting Remote Falcon Plugin" $LISTENER_LOG 2>/dev/null; then
    ok "listener log shows startup banner"
else
    fail "no startup banner in listener log"
fi

# Verify HTML pollution from common.php is suppressed (not in log)
if grep -q "<script\|FPP_VERSION\|MAXYEAR" $LISTENER_LOG 2>/dev/null; then
    fail "listener log contains HTML pollution from common.php (ob_start fix not effective)"
else
    ok "listener log clean (no common.php HTML pollution)"
fi

# --- Check 5: Restart Listener via /api/command (POST JSON) ---
echo
echo "=== /api/command Restart Listener ==="
INITIAL_PID=$LPID
RESTART_RESP=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d '{"command":"Remote Falcon - Restart Listener","args":[]}' \
    -w '\n__HTTP__%{http_code}' http://127.0.0.1/api/command 2>&1)
HTTP=$(echo "$RESTART_RESP" | grep -oE '__HTTP__[0-9]+' | tail -1 | sed 's/__HTTP__//')
if [ "$HTTP" = "200" ]; then
    ok "POST /api/command Restart Listener -> 200"
else
    fail "POST /api/command Restart Listener -> $HTTP"
fi
sleep 4
NEW_PID=$(cat $PID_FILE 2>/dev/null || echo "")
if [ -n "$NEW_PID" ] && [ "$NEW_PID" != "$INITIAL_PID" ] && [ -d "/proc/$NEW_PID" ]; then
    ok "new listener pid $NEW_PID running (was $INITIAL_PID)"
elif [ "$NEW_PID" = "$INITIAL_PID" ]; then
    fail "PID unchanged after restart command — restart did not happen"
else
    fail "no live listener pid after restart (pid file: $NEW_PID)"
fi

# --- Cleanup: stop listener so the container can exit cleanly ---
$PLUGIN_DIR/scripts/postStop.sh > /dev/null 2>&1 || true

echo
echo "=== summary ==="
echo "  $PASS passed, $FAIL failed"
exit $FAIL
