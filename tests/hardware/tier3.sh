#!/usr/bin/env bash
# Tier 3 orchestrator — final round of integration validation.
#
# Usage: FPP_HOST=192.168.1.80 ./tier3.sh

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

: "${FPP_HOST:?FPP_HOST is required}"

echo "============================================================"
echo " TIER 3 — full command-system integration validation"
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

run tier3-all-commands.sh

echo
echo "============================================================"
if [ "$OVERALL_FAIL" -eq 0 ]; then
    echo " TIER 3 — ALL PASS"
else
    echo " TIER 3 — $OVERALL_FAIL test(s) failed"
fi
echo "============================================================"

exit $OVERALL_FAIL
