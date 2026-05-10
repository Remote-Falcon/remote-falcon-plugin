#!/usr/bin/env bash
# Companion to tier1-stress-overnight.sh — formats current progress and
# (when stress is finished) the verdict summary.
#
# Read-only; safe to run any number of times during the run.
#
# Usage: FPP_HOST=192.168.1.80 ./tier1-stress-overnight-check.sh

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

LOG_PATH=/tmp/rf-stress-overnight.log
SUMMARY_PATH=/tmp/rf-stress-overnight-summary.txt
PIDFILE_PATH=/tmp/rf-stress-overnight-driver.pid

section "Stress status on $FPP_HOST"

DRIVER_PID=$(pi "cat $PIDFILE_PATH 2>/dev/null || true")
if [ -n "$DRIVER_PID" ] && pi "kill -0 $DRIVER_PID 2>/dev/null"; then
    ok "driver pid $DRIVER_PID still running"
    DRIVER_ETIME=$(pi "ps -o etime= -p $DRIVER_PID 2>/dev/null | tr -d ' '")
    echo "  elapsed: $DRIVER_ETIME"
else
    if pi "test -f $SUMMARY_PATH"; then
        ok "driver finished — summary file present"
    else
        fail "driver not running and no summary file — stress may have aborted"
    fi
fi

echo
echo "  --- last 20 progress lines ---"
pi "tail -20 $LOG_PATH 2>/dev/null || echo '(no log yet)'" | sed 's/^/  /'

echo
if pi "test -f $SUMMARY_PATH"; then
    section "Final summary"
    pi "cat $SUMMARY_PATH"
else
    section "Summary"
    echo "  (stress still in progress — summary will be written when the driver finishes)"
fi
