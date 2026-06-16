#!/usr/bin/env bash
# Companion to tier1-soak-overnight.sh — formats current progress and
# (if soak has completed) the final summary file.
#
# Safe to run any number of times during the soak. Read-only.
#
# Usage: FPP_HOST=192.168.1.80 ./tier1-soak-overnight-check.sh

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

LOG_PATH=/tmp/rf-soak-overnight.log
SUMMARY_PATH=/tmp/rf-soak-overnight-summary.txt
PIDFILE_PATH=/tmp/rf-soak-overnight-driver.pid

section "Soak status on $FPP_HOST"

DRIVER_PID=$(pi "cat $PIDFILE_PATH 2>/dev/null || true")
if [ -n "$DRIVER_PID" ] && pi "kill -0 $DRIVER_PID 2>/dev/null"; then
    ok "driver pid $DRIVER_PID still running"
    DRIVER_ETIME=$(pi "ps -o etime= -p $DRIVER_PID 2>/dev/null | tr -d ' '")
    echo "  elapsed: $DRIVER_ETIME"
else
    if [ -f /dev/null ] && pi "test -f $SUMMARY_PATH"; then
        ok "driver finished — summary file present"
    else
        fail "driver not running and no summary file — soak may have aborted"
    fi
fi

echo
echo "  --- last 15 progress lines ---"
pi "tail -15 $LOG_PATH 2>/dev/null || echo '(no log yet)'" | sed 's/^/  /'

echo
if pi "test -f $SUMMARY_PATH"; then
    section "Final summary"
    pi "cat $SUMMARY_PATH"
else
    section "Summary"
    echo "  (soak still in progress — summary will be written when the driver finishes)"
fi
