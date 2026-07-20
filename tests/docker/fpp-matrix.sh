#!/usr/bin/env bash
# FPP version matrix — run the plugin smoke against multiple FPP docker
# images in sequence. Surfaces FPP-version-specific incompatibilities
# (route changes, deprecated PHP APIs, common.php behavior drift) without
# needing a Pi for each version.
#
# Each version gets a fresh container:
#   - docker run falconchristmas/fpp:<version>
#   - wait for /api/system/status to return 200 (FPP boot is slow first time)
#   - docker cp the *current working tree* of the plugin into the container
#   - run scripts/fpp_install.sh
#   - run tests/docker/in-container-smoke.sh
#   - docker rm
#
# Why use the working tree (not a git ref): we want to validate the
# uncommitted changes you're about to merge, not whatever's on origin.
# This is the same shape as a local pre-PR check.
#
# Usage:
#   ./tests/docker/fpp-matrix.sh                       # default matrix
#   FPP_VERSIONS="9.4 8.4" ./tests/docker/fpp-matrix.sh  # subset
#
# First run is slow because docker pulls the image and FPP compiles
# /opt/fpp/src/fppinit on container start. Subsequent runs are ~30s/version.

set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"

# Default matrix: latest stable of each major branch the plugin claims
# to support. Per CLAUDE.md the plugin spans FPP 2.x-10.x; 4.x and earlier
# docker images aren't readily available so we start with 5.x. Note that
# 6.3.1 is a broken docker image (fppd never starts on launch); we use
# 6.x-master for that slot instead. FPP 10 has no stable docker tag yet,
# so its slot rides 10.x-master until one ships.
DEFAULT_VERSIONS=(5.5 6.x-master 7.5 8.4 9.5.3 10.x-master)
read -ra VERSIONS <<< "${FPP_VERSIONS:-${DEFAULT_VERSIONS[*]}}"

PORT=${PORT:-18080}
NAME_PREFIX=rf-matrix
WAIT_FPP_READY_SECS=${WAIT_FPP_READY_SECS:-180}

cleanup_container() {
    local name="$1"
    docker rm -f "$name" >/dev/null 2>&1 || true
}

wait_for_fpp() {
    # Apache comes up well before fppd does on FPP docker images, and
    # /api/commands returns empty when fppd is still booting. We wait
    # until fppd reports "running" so the plugin command registry is
    # actually available — otherwise we get phantom "command not
    # registered" failures. Some images (e.g. 6.3.1) never report
    # fppd:running at all; for those we fall back to apache-only after
    # half the deadline so we still report a meaningful failure.
    local deadline=$(( $(date +%s) + WAIT_FPP_READY_SECS ))
    local apache_seen_at=0
    while [ "$(date +%s)" -lt "$deadline" ]; do
        local body
        body=$(curl -sf --max-time 2 "http://127.0.0.1:$PORT/api/system/status" 2>/dev/null || echo "")
        if [ -n "$body" ]; then
            [ "$apache_seen_at" -eq 0 ] && apache_seen_at=$(date +%s)
            if echo "$body" | grep -q '"fppd":"running"'; then
                return 0
            fi
        fi
        # If apache has been up for a while but fppd never started,
        # bail out so the smoke can run against whatever we have.
        if [ "$apache_seen_at" -gt 0 ] \
                && [ $(( $(date +%s) - apache_seen_at )) -gt $(( WAIT_FPP_READY_SECS / 2 )) ]; then
            echo "  WARN  fppd never reached 'running' — falling back to apache-only"
            return 0
        fi
        sleep 2
    done
    return 1
}

run_one_version() {
    local version="$1"
    local name="${NAME_PREFIX}-${version//[^a-zA-Z0-9]/-}"

    echo
    echo "============================================================"
    echo " FPP $version  →  container $name"
    echo "============================================================"

    cleanup_container "$name"

    if ! docker run -d --rm --name "$name" -p "$PORT:80" \
            "falconchristmas/fpp:$version" >/dev/null; then
        echo "  FAIL  could not start container for $version"
        return 1
    fi

    echo "  Waiting for FPP to be ready (up to ${WAIT_FPP_READY_SECS}s)..."
    if ! wait_for_fpp; then
        echo "  FAIL  FPP not reachable on port $PORT after ${WAIT_FPP_READY_SECS}s"
        echo "  Last 30 lines of container logs:"
        docker logs --tail 30 "$name" 2>&1 | sed 's/^/    /'
        cleanup_container "$name"
        return 1
    fi
    echo "  FPP ready"

    # Copy the plugin source. Excluding vendor/ (PHPUnit deps) and tests/
    # because they aren't shipped to FPP installs, and excluding .git for
    # speed. We do this with tar | docker cp so the exclude list applies.
    echo "  Copying plugin source into container..."
    # macOS bsdtar embeds com.apple.* xattrs by default which produce a
    # flood of "Ignoring unknown extended header keyword" warnings from
    # the receiving GNU tar. --no-xattrs strips them at source.
    tar -C "$PLUGIN_ROOT" \
        --no-xattrs \
        --exclude='vendor' \
        --exclude='.git' \
        --exclude='tests/hardware' \
        --exclude='tests/docker' \
        --exclude='tests/integration' \
        --exclude='tests/virtual-fpp' \
        --exclude='node_modules' \
        -cf - . | docker exec -i "$name" bash -c '
            mkdir -p /home/fpp/media/plugins/remote-falcon
            tar -xf - -C /home/fpp/media/plugins/remote-falcon
            chown -R fpp:fpp /home/fpp/media/plugins/remote-falcon
        '

    echo "  Running fpp_install.sh..."
    # FPPDIR has to be passed through sudo via env(1); plain "export" before
    # sudo is dropped by the sudoers-clean environment. Note we don't fail
    # the matrix on install.sh hiccups — older FPP versions occasionally have
    # "setSetting: command not found" or similar non-fatal warnings but the
    # plugin tree itself is in place by then, which is what the smoke checks.
    docker exec "$name" bash -c '
        sudo -u fpp env FPPDIR=/opt/fpp \
            /home/fpp/media/plugins/remote-falcon/scripts/fpp_install.sh \
            >/tmp/install.log 2>&1 || true
        cat /tmp/install.log | sed "s/^/    install: /" | head -10
    '

    # Restart fppd so its plugin/command scanner picks up the freshly-copied
    # plugin tree. Without this /api/commands stays empty for the new plugin.
    echo "  Restarting fppd to pick up the plugin..."
    docker exec "$name" /opt/fpp/scripts/fppd_restart >/dev/null 2>&1 || true
    if ! wait_for_fpp; then
        echo "  FAIL  FPP didn't come back after fppd_restart"
        cleanup_container "$name"
        return 1
    fi
    # Settle period: api/system/status comes back before the plugin command
    # registry has finished re-scanning. Without this we see flaky "command
    # NOT registered" failures on a subset of commands.
    sleep 5

    echo "  Running smoke tests..."
    docker cp "$HERE/in-container-smoke.sh" "$name:/tmp/smoke.sh"
    docker exec "$name" chmod +x /tmp/smoke.sh
    if docker exec "$name" bash /tmp/smoke.sh; then
        cleanup_container "$name"
        return 0
    else
        cleanup_container "$name"
        return 1
    fi
}

# ---------- main ----------

echo "Plugin source: $PLUGIN_ROOT"
echo "Versions:      ${VERSIONS[*]}"
echo "Host port:     $PORT"

# Parallel arrays instead of associative, since dotted version strings
# ("9.4") aren't valid bash array keys.
RESULT_VERSIONS=()
RESULT_STATUSES=()
OVERALL_FAIL=0

for v in "${VERSIONS[@]}"; do
    if run_one_version "$v"; then
        RESULT_VERSIONS+=("$v"); RESULT_STATUSES+=("PASS")
    else
        RESULT_VERSIONS+=("$v"); RESULT_STATUSES+=("FAIL")
        OVERALL_FAIL=$(( OVERALL_FAIL + 1 ))
    fi
done

echo
echo "============================================================"
echo " FPP version matrix — results"
echo "============================================================"
for i in "${!RESULT_VERSIONS[@]}"; do
    printf "  %-10s %s\n" "${RESULT_VERSIONS[$i]}" "${RESULT_STATUSES[$i]}"
done
if [ "$OVERALL_FAIL" -eq 0 ]; then
    echo
    echo " ALL VERSIONS PASS"
else
    echo
    echo " $OVERALL_FAIL version(s) failed"
fi
echo "============================================================"

exit $OVERALL_FAIL
