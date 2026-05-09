#!/usr/bin/env bash
# Tier 1 #1 — listener stability soak.
#
# Runs the listener (with safe unreachable-RF settings) for SOAK_MINUTES
# minutes. Samples RSS memory and log line count at the start, midpoint,
# and end. Asserts:
#   - listener is still alive at the end (no crash)
#   - RSS memory growth is bounded (no obvious leak)
#   - log line growth rate is reasonable (no log explosion)
#
# Usage:
#   FPP_HOST=192.168.1.80 ./tier1-soak.sh
#   FPP_HOST=192.168.1.80 SOAK_MINUTES=10 ./tier1-soak.sh
#
# Default soak is 5 minutes — long enough to catch fast leaks, short enough
# to iterate during development. Run with SOAK_MINUTES=15 or higher for
# release-validation runs.

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

: "${SOAK_MINUTES:=5}"

section "Tier 1 #1 — listener stability soak (${SOAK_MINUTES} minutes)"

echo "Snapshotting Pi state..."
SNAP=$(pi_snapshot)
echo "  snapshot: ${SNAP%%:*}"
echo "  config:   ${SNAP##*:}"

echo "Stopping current listener..."
pi_stop_listener > /dev/null

echo "Installing perf branch (current branch tip)..."
pi_install_branch "${TEST_BRANCH:-chore/hw-tier1-validation}" > /dev/null

echo "Seeding safe settings (unreachable RF, no token)..."
pi_seed_safe_settings > /dev/null

echo "Starting listener..."
pi_start_listener_as_fpp > /dev/null

PID=$(pi_listener_pid)
if [ -z "$PID" ]; then
    fail "listener didn't start"
    pi_restore "$SNAP" > /dev/null
    summarize
    exit 1
fi
echo "  listener pid: $PID"

# Sample function: prints "rss=NN log_lines=NN"
sample() {
    pi "RSS=\$(ps -o rss= -p $PID 2>/dev/null | xargs); LL=\$(wc -l < /home/fpp/media/logs/remote-falcon-listener.log 2>/dev/null); echo \"rss_kb=\${RSS:-0} log_lines=\${LL:-0}\""
}

START_T=$(date +%s)
SAMPLE0=$(sample)
echo
echo "T+0s    : $SAMPLE0"

# Wait midpoint, then full duration. SOAK_MINUTES is total minutes.
MID_SEC=$(( SOAK_MINUTES * 30 ))
END_SEC=$(( SOAK_MINUTES * 60 ))

sleep $MID_SEC
ALIVE_MID=$(pi_listener_alive "$PID")
SAMPLE_MID=$(sample)
echo "T+${MID_SEC}s : $SAMPLE_MID  (alive=$ALIVE_MID)"

sleep $(( END_SEC - MID_SEC ))
ALIVE_END=$(pi_listener_alive "$PID")
SAMPLE_END=$(sample)
TOTAL_T=$(( $(date +%s) - START_T ))
echo "T+${END_SEC}s : $SAMPLE_END  (alive=$ALIVE_END)"
echo "(total elapsed: ${TOTAL_T}s)"

# Parse rss + log lines
parse_kv() {
    echo "$1" | sed -n "s/.*$2=\([0-9]*\).*/\1/p"
}

RSS_0=$(parse_kv "$SAMPLE0" rss_kb)
RSS_MID=$(parse_kv "$SAMPLE_MID" rss_kb)
RSS_END=$(parse_kv "$SAMPLE_END" rss_kb)
LL_0=$(parse_kv "$SAMPLE0" log_lines)
LL_END=$(parse_kv "$SAMPLE_END" log_lines)

echo
section "Assertions"

if [ "$ALIVE_END" = "alive" ]; then
    ok "listener still alive after ${SOAK_MINUTES}min"
else
    fail "listener dead at end of soak"
fi

# Memory growth: allow up to 50% growth from initial. PHP listener typically
# stabilizes around 20-30 MB; 50% headroom catches obvious leaks while
# tolerating normal allocation variance.
if [ -n "$RSS_0" ] && [ -n "$RSS_END" ] && [ "$RSS_0" -gt 0 ]; then
    GROWTH_PCT=$(( (RSS_END - RSS_0) * 100 / RSS_0 ))
    echo "  RSS growth: ${GROWTH_PCT}% ($RSS_0 → $RSS_END kB)"
    if [ "$GROWTH_PCT" -lt 50 ]; then
        ok "RSS growth within bounds (${GROWTH_PCT}% < 50%)"
    else
        fail "RSS growth exceeds 50% (possible leak)"
    fi
else
    fail "couldn't measure RSS"
fi

# Log growth: with verbose=false and unreachable RF, the listener should
# only log RF errors. Expected ~few lines/minute. >100 lines/minute would
# indicate a runaway loop.
LOG_GROWTH=$(( LL_END - LL_0 ))
LINES_PER_MIN=$(( LOG_GROWTH / SOAK_MINUTES ))
echo "  Log growth: $LOG_GROWTH lines over ${SOAK_MINUTES}min (${LINES_PER_MIN}/min)"
if [ "$LINES_PER_MIN" -lt 100 ]; then
    ok "log growth rate sane (${LINES_PER_MIN}/min < 100/min)"
else
    fail "log explosion (${LINES_PER_MIN}/min)"
fi

echo
echo "Restoring Pi to pre-test state..."
pi_restore "$SNAP" > /dev/null
echo "  done"

summarize
