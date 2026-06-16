#!/usr/bin/env bash
# Tier 1 orchestrator — runs all three hardware-validation tests in sequence.
#
# Usage: FPP_HOST=192.168.1.80 ./tier1.sh

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

: "${FPP_HOST:?FPP_HOST is required (e.g., FPP_HOST=192.168.1.80)}"

echo "============================================================"
echo " TIER 1 — hardware validation against $FPP_HOST"
echo "============================================================"

OVERALL_FAIL=0

run() {
    local script="$1"
    echo
    echo "------------------------------------------------------------"
    echo " $script"
    echo "------------------------------------------------------------"
    if FPP_HOST="$FPP_HOST" "$HERE/$script"; then
        echo "$script: PASS"
    else
        echo "$script: FAIL"
        OVERALL_FAIL=$(( OVERALL_FAIL + 1 ))
    fi
}

run tier1-restart-storm.sh
run tier1-connectivity.sh
run tier1-soak.sh

echo
echo "============================================================"
if [ "$OVERALL_FAIL" -eq 0 ]; then
    echo " TIER 1 — ALL PASS"
else
    echo " TIER 1 — $OVERALL_FAIL test(s) failed"
fi
echo "============================================================"

exit $OVERALL_FAIL
