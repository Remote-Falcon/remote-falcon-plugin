#!/usr/bin/env bash
# Tier 2 #1 — empirically observe perf 2.1 idle backoff on real hardware.
#
# Uses tcpdump on the Pi's loopback interface to count actual HTTP requests
# the listener makes to FPP's /api/system/status endpoint over a 30-second
# window while FPP is idle.
#
# Expected:
#   - With perf 2.1 idle backoff (sleep 5s when idle): 6 ± 2 polls / 30s
#   - Without backoff (would have polled every fppStatusCheckTime=1s): ~30 polls / 30s
#
# Asserts the count is at or below 10 — comfortably between the two cases,
# allows for jitter and timing skew.
#
# Why tcpdump and not apache logs: FPP doesn't enable apache access logging
# by default, and modifying apache config + reloading is intrusive on the
# user's Pi. tcpdump is non-invasive (no service config changes), captures
# the actual TCP traffic the listener sends to FPP localhost.
#
# Cache verification (perf 2.2) would require a non-RF playlist playing on
# FPP. That setup is fragile to do reliably from a test script and the
# integration tests already cover it deterministically. Skipped here in
# favor of idle backoff which is observable without staging FPP state.
#
# Usage: FPP_HOST=192.168.1.80 ./tier2-perf-observation.sh

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

: "${OBSERVATION_SECONDS:=30}"

section "Tier 2 #1 — empirical idle backoff observation (${OBSERVATION_SECONDS}s window)"

echo "Snapshotting Pi state..."
SNAP=$(pi_snapshot)
echo "  snapshot: ${SNAP%%:*}"

echo "Stopping current listener..."
pi_stop_listener > /dev/null

echo "Installing perf branch..."
pi_install_branch "${TEST_BRANCH:-chore/hw-tier2-validation}" > /dev/null

echo "Seeding safe settings (fppStatusCheckTime=1, unreachable RF)..."
pi_seed_safe_settings > /dev/null

echo "Starting listener..."
pi_start_listener_as_fpp > /dev/null
PID=$(pi_listener_pid)
echo "  listener pid: $PID"

# Confirm FPP is idle (no playing sequence) — that's the precondition
# for idle backoff to engage.
STATUS=$(pi 'curl -sf http://127.0.0.1/api/system/status | python3 -c "import json,sys; print(json.load(sys.stdin).get(\"status_name\", \"unknown\"))"')
echo "  FPP status: $STATUS"
if [ "$STATUS" != "idle" ]; then
    fail "FPP is not idle — idle backoff cannot be measured (status: $STATUS)"
    pi_restore "$SNAP" > /dev/null
    summarize
    exit 1
fi
ok "FPP is idle (precondition for backoff observation)"

# Capture loopback HTTP traffic with tcpdump.
# Run tcpdump as a background process inside the SSH session, write to /tmp,
# then we read it back. Use -nn (no name resolution) and -A (ASCII payload)
# so HTTP request lines are visible.
echo
echo "Capturing ${OBSERVATION_SECONDS}s of loopback HTTP traffic via tcpdump..."

CAP_FILE=/tmp/rf-tcpdump-$$.txt
# tcpdump prints each packet twice when using -A: once as a metadata line
# ("... HTTP: GET /api/...") and once as the raw payload echo ("GET /api/...").
# We filter to the metadata line by requiring "HTTP: GET /api/" so each
# poll is counted exactly once. (Anchoring with ^GET fails because the -A
# payload line is preceded by binary header bytes printed as dots.)
pi "sudo tcpdump -nn -i lo -l -A -s 200 'tcp port 80 and dst port 80' 2>/dev/null | grep --line-buffered 'HTTP: GET /api/' > $CAP_FILE 2>&1 &
    DUMP_PID=\$!
    sleep $OBSERVATION_SECONDS
    sudo kill \$DUMP_PID 2>/dev/null
    sleep 1"

echo "Capture complete. Analyzing..."

# Count requests. Note: `grep -c` returns exit 1 when the count is 0,
# which used to trip a `|| echo 0` fallback and produce "00". We just
# wrap with `|| true` so a no-match exit doesn't double-emit a count.
STATUS_HITS=$(pi "grep -c 'HTTP: GET /api/system/status' $CAP_FILE 2>/dev/null || true")
PLAYLIST_HITS=$(pi "grep -c 'HTTP: GET /api/playlist/' $CAP_FILE 2>/dev/null || true")
TOTAL_HITS=$(pi "wc -l < $CAP_FILE 2>/dev/null || echo 0")

# Parse — strip whitespace and newlines
STATUS_HITS=$(echo "$STATUS_HITS" | tr -d ' \n\r')
PLAYLIST_HITS=$(echo "$PLAYLIST_HITS" | tr -d ' \n\r')
TOTAL_HITS=$(echo "$TOTAL_HITS" | tr -d ' \n\r')

echo
echo "  /api/system/status hits: $STATUS_HITS  (expected: ~6 with idle backoff at 5s, vs ~30 without)"
echo "  /api/playlist/* hits:    $PLAYLIST_HITS  (expected: 0 — FPP is idle, no playlist to fetch)"
echo "  Total /api/ requests:    $TOTAL_HITS"

# Show sample of captured requests for debugging
echo
echo "  First 5 captured requests:"
pi "head -5 $CAP_FILE 2>/dev/null | sed 's/^/    /'"

echo
section "Assertions"

# Idle backoff is working if we saw FAR fewer than the no-backoff rate.
# Window is 30s. fppStatusCheckTime=1, so without backoff we'd see ~30.
# With backoff (5s sleep when idle), we'd see ~6. Assert <= 10 to allow
# for timing variance (e.g., the listener may have polled fast on the
# very first iteration before noticing idle state).
if [ "$STATUS_HITS" -ge 1 ] && [ "$STATUS_HITS" -le 10 ]; then
    ok "status polls observed: $STATUS_HITS in ${OBSERVATION_SECONDS}s — consistent with 5s idle backoff"
elif [ "$STATUS_HITS" -gt 10 ]; then
    fail "status polls $STATUS_HITS > 10 — idle backoff may not be working"
elif [ "$STATUS_HITS" = "0" ]; then
    fail "0 status polls captured — tcpdump may not have caught traffic (or listener hung)"
fi

# When idle, we should NOT be hitting /api/playlist/* (perf 2.1 skips
# the fetch when on remote playlist; FPP idle means we don't even enter
# the playing branch).
if [ "$PLAYLIST_HITS" = "0" ]; then
    ok "no playlist fetches (FPP idle, listener correctly skips)"
else
    fail "$PLAYLIST_HITS playlist fetches in idle state — should be 0"
fi

# Cleanup capture file
pi "rm -f $CAP_FILE"

echo
echo "Restoring Pi..."
pi_restore "$SNAP" > /dev/null
echo "  done"

summarize
