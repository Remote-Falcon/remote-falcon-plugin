#!/usr/bin/env bash
# Tier 2 orchestrator — runs all three Tier 2 hardware tests in sequence.
#
# Usage: FPP_HOST=192.168.1.80 ./tier2.sh

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

: "${FPP_HOST:?FPP_HOST is required}"

echo "============================================================"
echo " TIER 2 — live hardware perf + integration validation"
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

run tier2-command-system.sh
run tier2-settings-persistence.sh
run tier2-perf-observation.sh

echo
echo "============================================================"
if [ "$OVERALL_FAIL" -eq 0 ]; then
    echo " TIER 2 — ALL PASS"
else
    echo " TIER 2 — $OVERALL_FAIL test(s) failed"
fi
echo "============================================================"

exit $OVERALL_FAIL
