#!/usr/bin/env bash
# Tier 3 #1 — exercise every FPP command via /api/command/{name}.
#
# T2 covered "Restart Listener" only. The plugin ships nine other commands
# users can wire into playlists or fire from the UI:
#
#   Settings-only (no RF call):
#     - Turn Interrupt Schedule On    → interruptSchedule=true,  enabled=false, restarting=true
#     - Turn Interrupt Schedule Off   → interruptSchedule=false, enabled=false, restarting=true
#     - Stop Listener                 → enabled=false
#
#   RF-API-only (no-op with empty token, returns HTTP 200 either way):
#     - Turn Viewer Control On  → POST /updateViewerControl
#     - Turn Viewer Control Off → POST /updateViewerControl
#     - Turn Managed PSA On     → POST /updateManagedPsa
#     - Turn Managed PSA Off    → POST /updateManagedPsa
#     - Purge Queue/Reset Votes → DELETE /purgeQueue
#
# We don't exercise "Update Remote Playlist" because it requires a live
# FPP playlist named exactly the test value to exist on the Pi — too
# stateful to set up reliably from a test script.
#
# For each command we verify:
#   1. /api/command/{name} returns HTTP 200
#   2. Settings-only commands actually mutate the INI file as expected
#   3. RF-API commands no-op gracefully when token is empty (no PHP errors,
#      no leaked HTML pollution into stdout, exit cleanly)
#
# Usage: FPP_HOST=192.168.1.80 ./tier3-all-commands.sh

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

section "Tier 3 #1 — every FPP command via /api/command/"

echo "Snapshotting Pi state..."
SNAP=$(pi_snapshot)

echo "Stopping current listener..."
pi_stop_listener > /dev/null

echo "Installing perf branch..."
pi_install_branch "${TEST_BRANCH:-chore/hw-tier3-validation}" > /dev/null

echo "Seeding safe settings (empty token, unreachable RF)..."
pi_seed_safe_settings > /dev/null

# URL-encode a command name (only spaces; other chars in our names are
# already URL-safe). Keep this dumb on purpose — there's no shell percent-
# encoder we can rely on portably.
url_encode_name() {
    local name="$1"
    echo "${name// /%20}"
}

# Invoke a command via FPP's /api/command/{name} endpoint. Echoes the
# HTTP status; sets the global LAST_BODY for further assertions.
LAST_BODY=""
invoke_command() {
    local display_name="$1"
    local encoded
    encoded=$(url_encode_name "$display_name")
    local resp
    resp=$(pi "curl -sf -w '\n__HTTP_STATUS__%{http_code}' 'http://127.0.0.1/api/command/$encoded' || echo '__HTTP_STATUS__000'")
    local code
    code=$(echo "$resp" | grep -oE 'HTTP_STATUS__[0-9]+' | tail -1 | sed 's/HTTP_STATUS__//')
    LAST_BODY=$(echo "$resp" | sed 's/__HTTP_STATUS__[0-9]*$//')
    echo "$code"
}

# Read a setting's current INI value. Echoes the raw value (no quotes).
get_setting() {
    local key="$1"
    pi "grep -E '^$key = ' /home/fpp/media/config/plugin.remote-falcon 2>/dev/null | sed -E 's/^$key = \"(.*)\"$/\\1/'"
}

# ---------- Settings-only commands ----------

section "Settings-only: Turn Interrupt Schedule On"
# Pre-state: ensure interruptSchedule starts as false so we can observe the change
pi 'sudo sed -i "s/^interruptSchedule = .*/interruptSchedule = \"false\"/" /home/fpp/media/config/plugin.remote-falcon'
CODE=$(invoke_command "Remote Falcon - Turn Interrupt Schedule On")
[ "$CODE" = "200" ] && ok "  HTTP 200 from /api/command/.../Turn Interrupt Schedule On" || fail "  expected 200, got $CODE"
VAL=$(get_setting "interruptSchedule")
[ "$VAL" = "true" ] && ok "  interruptSchedule=true after On" || fail "  interruptSchedule=$VAL (expected true)"
VAL=$(get_setting "remoteFalconListenerRestarting")
[ "$VAL" = "true" ] && ok "  listener flagged restarting" || fail "  restarting flag not set ($VAL)"

section "Settings-only: Turn Interrupt Schedule Off"
CODE=$(invoke_command "Remote Falcon - Turn Interrupt Schedule Off")
[ "$CODE" = "200" ] && ok "  HTTP 200" || fail "  expected 200, got $CODE"
VAL=$(get_setting "interruptSchedule")
[ "$VAL" = "false" ] && ok "  interruptSchedule=false after Off" || fail "  interruptSchedule=$VAL (expected false)"

section "Settings-only: Stop Listener"
# Restore enabled=true first so we can verify Stop changes it
pi 'sudo sed -i "s/^remoteFalconListenerEnabled = .*/remoteFalconListenerEnabled = \"true\"/" /home/fpp/media/config/plugin.remote-falcon'
CODE=$(invoke_command "Remote Falcon - Stop Listener")
[ "$CODE" = "200" ] && ok "  HTTP 200" || fail "  expected 200, got $CODE"
VAL=$(get_setting "remoteFalconListenerEnabled")
[ "$VAL" = "false" ] && ok "  listener enabled=false after Stop" || fail "  enabled=$VAL (expected false)"

# ---------- RF-API commands (verify they no-op gracefully with empty token) ----------

# Each of these scripts checks `strlen($remoteToken) <= 1` and exit 0
# when the token is empty. We verify the command endpoint returns 200
# (FPP's command system runs the script successfully even when the script
# body short-circuits). We also check that no PHP error pollution leaks
# into the response body — a sign that include of common.php is properly
# suppressed.

assert_clean_no_op() {
    local cmd_name="$1"
    local code
    code=$(invoke_command "$cmd_name")
    if [ "$code" = "200" ]; then
        ok "  $cmd_name → HTTP 200"
    else
        fail "  $cmd_name → HTTP $code (expected 200)"
    fi
    # FPP's /api/command/ wraps script output in JSON; the body should
    # be the FPP completion sentinel, not random PHP warnings or HTML.
    if echo "$LAST_BODY" | grep -qiE 'php (warning|fatal|notice)|<html|<script|undefined'; then
        fail "  $cmd_name leaked PHP/HTML pollution into response"
        echo "      body excerpt: $(echo "$LAST_BODY" | head -c 200)"
    else
        ok "  $cmd_name body clean (no PHP/HTML pollution)"
    fi
}

section "RF-API: Turn Viewer Control On"
assert_clean_no_op "Remote Falcon - Turn Viewer Control On"

section "RF-API: Turn Viewer Control Off"
assert_clean_no_op "Remote Falcon - Turn Viewer Control Off"

section "RF-API: Turn Managed PSA On"
assert_clean_no_op "Remote Falcon - Turn Managed PSA On"

section "RF-API: Turn Managed PSA Off"
assert_clean_no_op "Remote Falcon - Turn Managed PSA Off"

section "RF-API: Purge Queue/Reset Votes"
assert_clean_no_op "Remote Falcon - Purge Queue/Reset Votes"

# ---------- Verify all expected commands are registered ----------

section "Command discovery"
COMMANDS=$(pi 'curl -sf http://127.0.0.1/api/commands' || echo "")
EXPECTED=(
    "Remote Falcon - Turn Interrupt Schedule Off"
    "Remote Falcon - Turn Interrupt Schedule On"
    "Remote Falcon - Purge Queue/Reset Votes"
    "Remote Falcon - Restart Listener"
    "Remote Falcon - Stop Listener"
    "Remote Falcon - Update Remote Playlist"
    "Remote Falcon - Turn Viewer Control Off"
    "Remote Falcon - Turn Viewer Control On"
    "Remote Falcon - Turn Managed PSA Off"
    "Remote Falcon - Turn Managed PSA On"
)
for name in "${EXPECTED[@]}"; do
    if echo "$COMMANDS" | grep -qF "$name"; then
        ok "  registered: $name"
    else
        fail "  NOT registered: $name"
    fi
done

echo
echo "Restoring Pi..."
pi_restore "$SNAP" > /dev/null
echo "  done"

summarize
