#!/usr/bin/env bash
# End-to-end smoke test for the Remote Falcon plugin against a virtual FPP.
# Invoked by ../../developer-files/fpp-test-env/scripts/smoke-test.sh, which
# populates these env vars:
#   FPP_BASE_URL, FPP_CONTAINER, MOCK_RF_BASE_URL, MOCK_RF_INTERNAL_URL,
#   MOCK_RF_STATE_DIR
#
# Prerequisites (the harness runs these in order):
#   1. spin-up.sh                — FPP + mock-rf-api are running
#   2. install-plugin.sh          — plugin tree is at /home/fpp/media/plugins/remote-falcon
#   3. seed-config.sh             — plugin.remote-falcon INI has remoteToken,
#                                   pluginsApiPath, remotePlaylist, etc.
#   4. (caller starts the listener via postStart.sh before calling this)

set -euo pipefail

PASS=0
FAIL=0

ok()   { printf "  \033[32m✓\033[0m %s\n" "$1"; PASS=$((PASS+1)); }
fail() { printf "  \033[31m✗\033[0m %s\n" "$1"; FAIL=$((FAIL+1)); }
section() { printf "\n\033[1m%s\033[0m\n" "$1"; }

clear_recordings() {
    echo '[]' > "$MOCK_RF_STATE_DIR/recordings.json"
}

set_route() {
    # set_route PATH JSON_BODY_OR_ROUTE_OBJECT
    local path="$1"
    local route="$2"
    python3 - "$MOCK_RF_STATE_DIR/config.json" "$path" "$route" <<'PY'
import json, sys
cfg_path, key, route_str = sys.argv[1], sys.argv[2], sys.argv[3]
with open(cfg_path) as f:
    try:
        cfg = json.load(f)
    except json.JSONDecodeError:
        cfg = {}
cfg[key] = json.loads(route_str)
with open(cfg_path, "w") as f:
    json.dump(cfg, f)
PY
}

recording_paths() {
    python3 -c "import json,sys; [print(r['path']) for r in json.load(open('$MOCK_RF_STATE_DIR/recordings.json'))]"
}

# --- 1. Plugin install + listener boot ---

section "Plugin install + listener boot"

if curl -sf "$FPP_BASE_URL/api/plugin" | grep -q '"remote-falcon"'; then
    ok "plugin appears in /api/plugin"
else
    fail "plugin not registered with FPP"
fi

if docker exec "$FPP_CONTAINER" test -f /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid; then
    ok "listener PID file written"
else
    fail "listener PID file missing"
fi

PID=$(docker exec "$FPP_CONTAINER" cat /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid 2>/dev/null || echo "")
if [ -n "$PID" ] && docker exec "$FPP_CONTAINER" sh -c "kill -0 $PID 2>/dev/null"; then
    ok "listener process running (pid $PID)"
else
    fail "listener process not running"
fi

if docker exec "$FPP_CONTAINER" grep -q "Starting Remote Falcon Plugin" /home/fpp/media/logs/remote-falcon-listener.log; then
    ok "listener log shows startup banner"
else
    fail "listener log missing startup banner"
fi

# --- 2. Listener calls mock RF on init ---

section "Listener init → mock RF"

# Wait briefly for the listener to make its initial calls.
sleep 2

if recording_paths | grep -qx '/remotePreferences'; then
    ok "listener fetched /remotePreferences"
else
    fail "listener did not fetch /remotePreferences"
fi

# --- 3. Settings page loads ---

section "Settings page"

if curl -sf -o /dev/null "$FPP_BASE_URL/plugin.php?plugin=remote-falcon&page=remote_falcon_ui.html"; then
    ok "settings page returns 200"
else
    fail "settings page failed to load"
fi

# --- 4. Restart listener via plugin command ---

section "Restart listener"

OLD_PID="$PID"

# The plugin's restart command sets two flags that the listener loop
# notices and reloads its configuration on. The PID stays the same
# (this is "reload settings" not "kill and respawn") — that's the
# documented existing behavior, not a bug in T2.
docker exec "$FPP_CONTAINER" /home/fpp/media/plugins/remote-falcon/commands/restart_remote_falcon.php >/dev/null
sleep 3

if docker exec "$FPP_CONTAINER" sh -c "kill -0 $OLD_PID 2>/dev/null"; then
    ok "listener still running after restart command (process-level reload, not respawn)"
else
    fail "listener died unexpectedly during restart"
fi

if docker exec "$FPP_CONTAINER" grep -q "Restarting Remote Falcon Plugin" /home/fpp/media/logs/remote-falcon-listener.log; then
    ok "listener log shows restart banner"
else
    fail "listener log missing restart banner"
fi

# --- 5. Stop listener via plugin command ---

section "Stop listener"

docker exec "$FPP_CONTAINER" /home/fpp/media/plugins/remote-falcon/commands/stop_remote_falcon.php >/dev/null
# Stop just sets a flag; the loop reads it on next iteration. Wait a tick
# longer than fppStatusCheckTime + a small buffer.
sleep 3

# Same caveat — "stop" pauses the loop but doesn't kill the process. This
# is documented existing behavior. Real shutdown happens via postStop.sh.
if docker exec "$FPP_CONTAINER" sh -c "kill -0 $OLD_PID 2>/dev/null"; then
    ok "process still alive after stop command (existing behavior; loop pauses)"
else
    fail "process died unexpectedly after stop command"
fi

# --- 6. postStop.sh actually kills the listener ---

section "postStop.sh terminates listener"

docker exec "$FPP_CONTAINER" /home/fpp/media/plugins/remote-falcon/scripts/postStop.sh
sleep 1

if docker exec "$FPP_CONTAINER" sh -c "kill -0 $OLD_PID 2>/dev/null"; then
    fail "listener still running after postStop.sh"
else
    ok "listener terminated by postStop.sh"
fi

if ! docker exec "$FPP_CONTAINER" test -f /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid; then
    ok "PID file removed by postStop.sh"
else
    fail "PID file still present after postStop.sh"
fi

# --- summary ---

printf "\n\033[1mSummary:\033[0m %d passed, %d failed\n" "$PASS" "$FAIL"
exit $FAIL
