#!/usr/bin/env bash
# Tier 2 #3 — settings persistence via the FPP plugin settings API.
#
# The plugin's UI saves settings by POSTing to
#   /api/plugin/remote-falcon/settings/{key}
# which FPP routes to WriteSettingToFile in /opt/fpp/www/common.php.
# WriteSettingToFile uses flock on the config file and would fail with
# "flock(): Argument #1 ($stream) must be of type resource, bool given"
# if the file ownership is wrong (root-owned config that the apache user
# can't write). We chown to fpp:fpp in our install path; this test
# verifies that fix holds end-to-end against real FPP.
#
# Note on urlencoding: plugin-internal code calls WriteSettingToFile
# with urlencode() applied (see CLAUDE.md), but FPP's own HTTP settings
# endpoint does NOT urlencode the POST body before writing. So values
# round-trip verbatim through the API path. We assert raw value match.
#
# For each test setting:
#   1. POST a value to /api/plugin/remote-falcon/settings/{key}
#   2. Read the INI file directly, verify the key is present
#   3. GET the value back via the same endpoint, verify round-trip
#
# Usage: FPP_HOST=192.168.1.80 ./tier2-settings-persistence.sh

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

section "Tier 2 #3 — settings persistence via FPP plugin API"

echo "Snapshotting Pi state..."
SNAP=$(pi_snapshot)

echo "Stopping current listener..."
pi_stop_listener > /dev/null

echo "Installing perf branch..."
pi_install_branch "${TEST_BRANCH:-chore/hw-tier2-validation}" > /dev/null

echo "Seeding safe settings as a baseline..."
pi_seed_safe_settings > /dev/null

# Set of (key, raw_value) tuples. Keys are real plugin settings; values
# include a space-bearing string to exercise quoting/round-trip on the
# /api/plugin/{name}/settings/{key} HTTP path.
TESTS=(
    "interruptSchedule|true"
    "requestFetchTime|5"
    "additionalWaitTime|0"
    "fppStatusCheckTime|2"
    "verboseLogging|true"
    "remotePlaylist|My Show 2026"
)

# Function: POST a setting via FPP API, verify INI persistence and
# round-trip via GET. FPP writes the raw value verbatim on this path.
test_setting() {
    local key="$1"
    local raw="$2"

    echo
    echo "  Setting: $key = '$raw'"

    # POST via FPP plugin settings endpoint
    local post_resp
    post_resp=$(pi "curl -sf -X POST -H 'Content-Type: text/plain' --data '$raw' 'http://127.0.0.1/api/plugin/remote-falcon/settings/$key' 2>&1 || echo 'CURL_FAIL'")
    if echo "$post_resp" | grep -q '"status":"OK"'; then
        ok "  POST $key returned status:OK"
    elif echo "$post_resp" | grep -qi 'flock'; then
        fail "  POST $key tripped the flock bug — config file ownership is wrong"
        echo "      response: $post_resp"
    else
        fail "  POST $key did not return OK"
        echo "      response: $post_resp"
    fi

    # Read INI file directly; verify the persisted form (raw verbatim).
    local ini_line
    ini_line=$(pi "grep -E '^$key = ' /home/fpp/media/config/plugin.remote-falcon 2>/dev/null || echo MISSING")
    if echo "$ini_line" | grep -qF "= \"$raw\""; then
        ok "  INI line matches: $ini_line"
    else
        fail "  INI mismatch — got: $ini_line"
    fi

    # GET via the same endpoint, verify round-trip decode
    local get_resp
    get_resp=$(pi "curl -sf 'http://127.0.0.1/api/plugin/remote-falcon/settings/$key' 2>/dev/null || echo CURL_FAIL")
    # FPP returns JSON: {"status":"OK","key":"value"}
    if echo "$get_resp" | grep -qE "\"$key\":\"$raw\""; then
        ok "  GET round-trip returned the raw value (urldecoded)"
    else
        fail "  GET round-trip mismatch — got: $get_resp"
    fi
}

section "Per-setting POST + INI verify + GET round-trip"

for test in "${TESTS[@]}"; do
    IFS='|' read -r key raw <<< "$test"
    test_setting "$key" "$raw"
done

echo
echo "Restoring Pi..."
pi_restore "$SNAP" > /dev/null
echo "  done"

summarize
