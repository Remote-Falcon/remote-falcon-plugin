#!/usr/bin/env bash
# Tier 1 #5 — overnight stress (restart + settings torture).
#
# Orthogonal to the overnight soak: the soak observes a quiescent
# listener for 8h to detect leaks; the stress test exercises the *active*
# code paths the perf branch actually changed:
#
#   - restart_remote_falcon.php's PHP-side flock (race regression guard)
#   - postStop/postStart cross-user kill (cross-user kill via sudo fallback)
#   - WriteSettingToFile under contention with the restart command
#   - the listener's mtime-based settings reload (perf 2.4)
#
# The driver does, in parallel for SOAK_HOURS hours:
#   - every RESTART_INTERVAL seconds: POST /api/command Restart Listener,
#     wait 5s for things to settle, assert exactly one listener is running
#   - every SETTINGS_INTERVAL seconds: rotate through a set of safe
#     settings keys, POST a new value via /api/plugin/.../settings, GET
#     it back, assert the round-trip succeeded
#   - every SAMPLE_INTERVAL seconds: snapshot listener PID, RSS, log size
#
# At the end: stop the listener, restore the snapshot, write a verdict
# summary. Pass thresholds focus on the regressions we care about most:
#   - zero multi-listener events (the original storm bug)
#   - <0.5% restart failures
#   - <1% settings failures
#   - bounded RSS drift over the run
#
# Detached via setsid+nohup so it survives SSH disconnect and Mac sleep.
# Companion: tier1-stress-overnight-check.sh formats progress + summary.
#
# Usage:
#   FPP_HOST=192.168.1.80 ./tier1-stress-overnight.sh
# Env:
#   SOAK_HOURS=8                 total wall-clock window
#   STRESS_SECS                  optional override for SOAK_HOURS, in seconds
#   RESTART_INTERVAL=30          seconds between restart commands
#   SETTINGS_INTERVAL=10         seconds between settings POSTs
#   SAMPLE_INTERVAL=60           seconds between PID/RSS samples
#   TEST_BRANCH=perf/listener-tightening

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

: "${SOAK_HOURS:=8}"
: "${RESTART_INTERVAL:=30}"
: "${SETTINGS_INTERVAL:=10}"
: "${SAMPLE_INTERVAL:=60}"
: "${TEST_BRANCH:=perf/listener-tightening}"

if [ -n "${STRESS_SECS:-}" ]; then
    DURATION_SECS=$STRESS_SECS
else
    DURATION_SECS=$(( SOAK_HOURS * 3600 ))
fi
EXPECTED_RESTARTS=$(( DURATION_SECS / RESTART_INTERVAL ))
EXPECTED_SETTINGS=$(( DURATION_SECS / SETTINGS_INTERVAL ))
EXPECTED_SAMPLES=$(( DURATION_SECS / SAMPLE_INTERVAL ))

DRIVER_PATH=/tmp/rf-stress-overnight-driver.sh
LOG_PATH=/tmp/rf-stress-overnight.log
SUMMARY_PATH=/tmp/rf-stress-overnight-summary.txt
PIDFILE_PATH=/tmp/rf-stress-overnight-driver.pid

section "Tier 1 #5 — overnight stress (${DURATION_SECS}s = ~$(( DURATION_SECS / 3600 ))h)"

# Bail if a previous stress driver (or the soak driver — different file)
# is still running.
EXISTING=$(pi "test -f $PIDFILE_PATH && cat $PIDFILE_PATH || true")
if [ -n "$EXISTING" ] && pi "kill -0 $EXISTING 2>/dev/null"; then
    fail "A stress driver is already running on the Pi (PID $EXISTING)."
    echo "  Abort it: ssh fpp@$FPP_HOST 'sudo kill $EXISTING'"
    exit 1
fi
SOAK_PIDFILE_EXISTING=$(pi "test -f /tmp/rf-soak-overnight-driver.pid && cat /tmp/rf-soak-overnight-driver.pid || true")
if [ -n "$SOAK_PIDFILE_EXISTING" ] && pi "kill -0 $SOAK_PIDFILE_EXISTING 2>/dev/null"; then
    fail "The overnight SOAK driver is still running on the Pi (PID $SOAK_PIDFILE_EXISTING)."
    echo "  The soak and stress tests can't run simultaneously — they'd fight over the listener."
    exit 1
fi

pi "rm -f $LOG_PATH $SUMMARY_PATH $PIDFILE_PATH" > /dev/null

section "Pre-flight"
PROBE=$(pi "curl -sf -o /dev/null -w '%{http_code}' http://127.0.0.1/api/system/status || echo 000")
if [ "$PROBE" = "200" ]; then
    ok "FPP /api/system/status reachable on Pi"
else
    fail "FPP not reachable (got $PROBE) — aborting"
    exit 1
fi

GIT_PROBE=$(pi "git ls-remote --heads https://github.com/Remote-Falcon/remote-falcon-plugin.git '$TEST_BRANCH' | wc -l" | tr -d ' \n\r')
if [ "$GIT_PROBE" = "1" ]; then
    ok "branch '$TEST_BRANCH' exists on origin"
else
    fail "branch '$TEST_BRANCH' not found on origin"
    exit 1
fi

section "Building remote driver"

cat > /tmp/rf-stress-driver-local.sh <<DRIVER
#!/bin/bash
set -uo pipefail

DURATION_SECS=$DURATION_SECS
RESTART_INTERVAL=$RESTART_INTERVAL
SETTINGS_INTERVAL=$SETTINGS_INTERVAL
SAMPLE_INTERVAL=$SAMPLE_INTERVAL
TEST_BRANCH="$TEST_BRANCH"
LOG_PATH="$LOG_PATH"
SUMMARY_PATH="$SUMMARY_PATH"
PIDFILE_PATH="$PIDFILE_PATH"

PLUGIN_DIR=/home/fpp/media/plugins/remote-falcon
LISTENER_LOG=/home/fpp/media/logs/remote-falcon-listener.log
PLUGIN_PIDFILE=\$PLUGIN_DIR/remote_falcon_listener.pid
CONFIG_FILE=/home/fpp/media/config/plugin.remote-falcon

log_d() {
    echo "[\$(date -Iseconds)] \$1" >> "\$LOG_PATH"
}

echo \$\$ > "\$PIDFILE_PATH"

START_EPOCH=\$(date +%s)
log_d "stress driver starting (pid \$\$, duration \${DURATION_SECS}s, branch \$TEST_BRANCH)"

# --- Setup phase ---
STAMP=\$(date +%Y%m%d-%H%M%S)
SNAP_TAR=/tmp/rf-stress-snap-\${STAMP}.tar.gz
SNAP_CFG=/tmp/rf-stress-cfg-\${STAMP}.before
sudo tar -czf "\$SNAP_TAR" -C /home/fpp/media/plugins remote-falcon 2>/dev/null
sudo cp \$CONFIG_FILE "\$SNAP_CFG" 2>/dev/null
log_d "snapshot: \$SNAP_TAR + \$SNAP_CFG"

sudo "\$PLUGIN_DIR/scripts/postStop.sh" >/dev/null 2>&1 || true
sleep 1

cd /home/fpp/media/plugins
sudo rm -rf remote-falcon
if ! sudo git clone --branch "\$TEST_BRANCH" --quiet \
        https://github.com/Remote-Falcon/remote-falcon-plugin.git remote-falcon; then
    echo "FAIL — git clone of \$TEST_BRANCH failed at setup" > "\$SUMMARY_PATH"
    exit 1
fi
sudo chown -R fpp:fpp remote-falcon
sudo -u fpp bash -c 'export FPPDIR=/opt/fpp && /home/fpp/media/plugins/remote-falcon/scripts/fpp_install.sh' >/dev/null 2>&1
log_d "perf branch installed"

# Safe baseline — empty token, unreachable RF, listener enabled.
sudo tee \$CONFIG_FILE > /dev/null <<'CFG'
pluginVersion = "2026.01.02.01"
remotePlaylist = "RF-Test"
interruptSchedule = "false"
remoteToken = ""
requestFetchTime = "3"
additionalWaitTime = "0"
fppStatusCheckTime = "1"
pluginsApiPath = "http://127.0.0.1:9999"
verboseLogging = "false"
remoteFalconListenerEnabled = "true"
remoteFalconListenerRestarting = "false"
init = "true"
autoSyncMetadata = "false"
CFG
sudo chown fpp:fpp \$CONFIG_FILE
sudo chmod 664 \$CONFIG_FILE
sudo truncate -s 0 \$LISTENER_LOG 2>/dev/null
sudo chown fpp:fpp \$LISTENER_LOG 2>/dev/null
log_d "safe settings seeded"

sudo -u fpp "\$PLUGIN_DIR/scripts/postStart.sh" >/dev/null 2>&1
sleep 3
INITIAL_PID=\$(cat "\$PLUGIN_PIDFILE" 2>/dev/null || echo "")
if [ -z "\$INITIAL_PID" ] || ! sudo test -d "/proc/\$INITIAL_PID"; then
    echo "FAIL — initial listener did not start (pid=\$INITIAL_PID)" > "\$SUMMARY_PATH"
    exit 1
fi
log_d "listener started, pid=\$INITIAL_PID"

INITIAL_RSS=\$(awk '{print \$1}' < <(ps -o rss= -p "\$INITIAL_PID" 2>/dev/null) | tr -d ' ')
INITIAL_LOG_LINES=\$(sudo wc -l < \$LISTENER_LOG 2>/dev/null | tr -d ' ' || echo 0)

# --- Counters ---
RESTART_TOTAL=0
RESTART_HTTP_OK=0
RESTART_HTTP_FAIL=0
SETTINGS_TOTAL=0
SETTINGS_OK=0
SETTINGS_FAIL=0
MULTI_LISTENER_EVENTS=0
LISTENER_DEAD_EVENTS=0
SAMPLES=0

# Settings rotation. Avoid touching the two flags the restart command
# writes (remoteFalconListenerEnabled, remoteFalconListenerRestarting) —
# those are owned by the restart logic, not the user.
SETTINGS_KEYS=(interruptSchedule requestFetchTime additionalWaitTime fppStatusCheckTime verboseLogging)
SETTINGS_VALUES=(
    "true|false"
    "1|2|3|5"
    "0|1|2"
    "1|2"
    "true|false"
)
SETTINGS_TICK=0

next_settings_value() {
    local idx="\$1"
    local options="\${SETTINGS_VALUES[\$idx]}"
    IFS='|' read -ra opts <<< "\$options"
    local pick=\$(( SETTINGS_TICK % \${#opts[@]} ))
    echo "\${opts[\$pick]}"
}

count_listeners() {
    pgrep -fc "/usr/bin/php /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.php" 2>/dev/null || echo 0
}

# --- Stress loop ---
NEXT_RESTART=\$(( START_EPOCH + RESTART_INTERVAL ))
NEXT_SETTINGS=\$(( START_EPOCH + SETTINGS_INTERVAL ))
NEXT_SAMPLE=\$(( START_EPOCH + SAMPLE_INTERVAL ))
END_EPOCH=\$(( START_EPOCH + DURATION_SECS ))

while [ "\$(date +%s)" -lt "\$END_EPOCH" ]; do
    NOW=\$(date +%s)

    # Restart command tick
    if [ "\$NOW" -ge "\$NEXT_RESTART" ]; then
        RESTART_TOTAL=\$(( RESTART_TOTAL + 1 ))
        RESP=\$(curl -s -X POST -H 'Content-Type: application/json' \\
            -d '{"command":"Remote Falcon - Restart Listener","args":[]}' \\
            -w '\\n__HTTP__%{http_code}' --max-time 30 \\
            http://127.0.0.1/api/command 2>&1 || echo '__HTTP__000')
        HTTP=\$(echo "\$RESP" | grep -oE '__HTTP__[0-9]+' | tail -1 | sed 's/__HTTP__//')
        if [ "\$HTTP" = "200" ]; then
            RESTART_HTTP_OK=\$(( RESTART_HTTP_OK + 1 ))
        else
            RESTART_HTTP_FAIL=\$(( RESTART_HTTP_FAIL + 1 ))
            log_d "WARN restart \$RESTART_TOTAL got HTTP \$HTTP"
        fi
        sleep 5
        # Critical assertion: exactly one listener after restart settles.
        LCOUNT=\$(count_listeners)
        if [ "\$LCOUNT" -gt 1 ]; then
            MULTI_LISTENER_EVENTS=\$(( MULTI_LISTENER_EVENTS + 1 ))
            log_d "FAIL multi-listener event after restart \$RESTART_TOTAL: \$LCOUNT listeners running"
        elif [ "\$LCOUNT" -eq 0 ]; then
            LISTENER_DEAD_EVENTS=\$(( LISTENER_DEAD_EVENTS + 1 ))
            log_d "FAIL no listener running after restart \$RESTART_TOTAL"
        fi
        NEXT_RESTART=\$(( NOW + RESTART_INTERVAL ))
    fi

    # Settings POST tick
    if [ "\$NOW" -ge "\$NEXT_SETTINGS" ]; then
        SETTINGS_TOTAL=\$(( SETTINGS_TOTAL + 1 ))
        KEY_IDX=\$(( SETTINGS_TICK % \${#SETTINGS_KEYS[@]} ))
        KEY="\${SETTINGS_KEYS[\$KEY_IDX]}"
        VALUE=\$(next_settings_value "\$KEY_IDX")

        POST_RESP=\$(curl -s -X POST -H 'Content-Type: text/plain' --data "\$VALUE" \\
            --max-time 10 \\
            "http://127.0.0.1/api/plugin/remote-falcon/settings/\$KEY" 2>&1 || echo CURL_FAIL)
        if echo "\$POST_RESP" | grep -q '"status":"OK"'; then
            # Round-trip via GET
            GET_RESP=\$(curl -s --max-time 10 \\
                "http://127.0.0.1/api/plugin/remote-falcon/settings/\$KEY" 2>/dev/null)
            if echo "\$GET_RESP" | grep -qE "\"\$KEY\":\"\$VALUE\""; then
                SETTINGS_OK=\$(( SETTINGS_OK + 1 ))
            else
                SETTINGS_FAIL=\$(( SETTINGS_FAIL + 1 ))
                log_d "WARN settings round-trip mismatch for \$KEY=\$VALUE: GET returned \$(echo \$GET_RESP | head -c 80)"
            fi
        else
            SETTINGS_FAIL=\$(( SETTINGS_FAIL + 1 ))
            log_d "WARN settings POST failed for \$KEY=\$VALUE: \$(echo \$POST_RESP | head -c 80)"
        fi

        SETTINGS_TICK=\$(( SETTINGS_TICK + 1 ))
        NEXT_SETTINGS=\$(( NOW + SETTINGS_INTERVAL ))
    fi

    # Sample tick
    if [ "\$NOW" -ge "\$NEXT_SAMPLE" ]; then
        SAMPLES=\$(( SAMPLES + 1 ))
        ELAPSED=\$(( NOW - START_EPOCH ))
        CURRENT_PID=\$(cat "\$PLUGIN_PIDFILE" 2>/dev/null || echo "")
        CURRENT_RSS=0
        if [ -n "\$CURRENT_PID" ] && sudo test -d "/proc/\$CURRENT_PID"; then
            CURRENT_RSS=\$(awk '{print \$1}' < <(ps -o rss= -p "\$CURRENT_PID" 2>/dev/null) | tr -d ' ')
        fi
        LOG_LINES=\$(sudo wc -l < \$LISTENER_LOG 2>/dev/null | tr -d ' ' || echo 0)
        log_d "sample=\$SAMPLES elapsed=\${ELAPSED}s pid=\$CURRENT_PID rss=\${CURRENT_RSS:-0}KB log=\$LOG_LINES restarts=\$RESTART_TOTAL settings=\$SETTINGS_TOTAL multi=\$MULTI_LISTENER_EVENTS dead=\$LISTENER_DEAD_EVENTS"
        NEXT_SAMPLE=\$(( NOW + SAMPLE_INTERVAL ))
    fi

    sleep 1
done

log_d "stress window complete; collecting final metrics"

FINAL_PID=\$(cat "\$PLUGIN_PIDFILE" 2>/dev/null || echo "")
FINAL_RSS=0
if [ -n "\$FINAL_PID" ] && sudo test -d "/proc/\$FINAL_PID"; then
    FINAL_RSS=\$(awk '{print \$1}' < <(ps -o rss= -p "\$FINAL_PID" 2>/dev/null) | tr -d ' ')
fi
FINAL_LOG_LINES=\$(sudo wc -l < \$LISTENER_LOG 2>/dev/null | tr -d ' ' || echo 0)
ERROR_LINES=\$(sudo grep -cE 'ERROR|FATAL' \$LISTENER_LOG 2>/dev/null | tr -d ' ' || echo 0)

# --- Tear-down ---
log_d "stopping listener and restoring snapshot"
sudo "\$PLUGIN_DIR/scripts/postStop.sh" >/dev/null 2>&1 || true
sleep 1

sudo rm -f /etc/logrotate.d/remote-falcon
cd /home/fpp/media/plugins
sudo rm -rf remote-falcon
sudo tar -xzf "\$SNAP_TAR" -C /home/fpp/media/plugins/
sudo chown -R fpp:fpp remote-falcon
sudo cp "\$SNAP_CFG" \$CONFIG_FILE
sudo chown fpp:fpp \$CONFIG_FILE
sudo chmod 664 \$CONFIG_FILE
sudo -u fpp "\$PLUGIN_DIR/scripts/postStart.sh" >/dev/null 2>&1
sleep 2
RESTORED_PID=\$(cat "\$PLUGIN_PIDFILE" 2>/dev/null || echo "")
log_d "snapshot restored; production listener pid=\$RESTORED_PID"

# --- Verdict ---
RSS_DELTA=\$(( FINAL_RSS - INITIAL_RSS ))
LOG_DELTA=\$(( FINAL_LOG_LINES - INITIAL_LOG_LINES ))
WALL_SECS=\$(( \$(date +%s) - START_EPOCH ))

# Pass thresholds:
#   - any multi-listener event = hard FAIL (the bug we explicitly fixed)
#   - listener-dead events <= 1% of restarts (transient timing OK)
#   - restart HTTP fails < 0.5% of restarts
#   - settings round-trip fails < 1% of settings POSTs
#   - RSS drift < 5MB
RESTART_FAIL_BUDGET=\$(( (RESTART_TOTAL / 200) + 1 ))     # ~0.5%
SETTINGS_FAIL_BUDGET=\$(( (SETTINGS_TOTAL / 100) + 1 ))   # ~1%
LISTENER_DEAD_BUDGET=\$(( (RESTART_TOTAL / 100) + 1 ))   # ~1%

VERDICT="PASS"
[ "\$MULTI_LISTENER_EVENTS" -gt 0 ] && VERDICT="FAIL"
[ "\$RESTART_HTTP_FAIL" -gt "\$RESTART_FAIL_BUDGET" ] && VERDICT="FAIL"
[ "\$SETTINGS_FAIL" -gt "\$SETTINGS_FAIL_BUDGET" ] && VERDICT="FAIL"
[ "\$LISTENER_DEAD_EVENTS" -gt "\$LISTENER_DEAD_BUDGET" ] && VERDICT="FAIL"
[ "\$RSS_DELTA" -gt 5000 ] && VERDICT="FAIL"

cat > "\$SUMMARY_PATH" <<SUMMARY
=== Remote Falcon overnight stress — \$VERDICT ===
duration            : \${WALL_SECS}s (\$(( WALL_SECS / 3600 ))h \$(( (WALL_SECS / 60) % 60 ))m)
branch              : \$TEST_BRANCH

restart torture (every \${RESTART_INTERVAL}s):
  total fired       : \$RESTART_TOTAL  (expected ~$EXPECTED_RESTARTS)
  HTTP 200          : \$RESTART_HTTP_OK
  HTTP failures     : \$RESTART_HTTP_FAIL  (budget \$RESTART_FAIL_BUDGET)
  multi-listener    : \$MULTI_LISTENER_EVENTS  (CRITICAL — must be 0)
  listener-dead     : \$LISTENER_DEAD_EVENTS  (budget \$LISTENER_DEAD_BUDGET)

settings torture (every \${SETTINGS_INTERVAL}s):
  total POSTed      : \$SETTINGS_TOTAL  (expected ~$EXPECTED_SETTINGS)
  round-trip OK     : \$SETTINGS_OK
  failures          : \$SETTINGS_FAIL  (budget \$SETTINGS_FAIL_BUDGET)

memory:
  RSS start         : \${INITIAL_RSS} KB
  RSS end           : \${FINAL_RSS} KB
  RSS delta         : \${RSS_DELTA} KB

log:
  lines start       : \$INITIAL_LOG_LINES
  lines end         : \$FINAL_LOG_LINES
  growth            : \$LOG_DELTA
  ERROR/FATAL       : \$ERROR_LINES

production listener restored: pid=\$RESTORED_PID

thresholds for PASS:
  - multi-listener events == 0
  - restart HTTP failures <= 0.5% of total restarts
  - settings round-trip failures <= 1% of total POSTs
  - listener-dead events <= 1% of total restarts
  - RSS drift < 5000 KB

raw progress log: \$LOG_PATH
SUMMARY

log_d "summary written to \$SUMMARY_PATH (\$VERDICT)"
rm -f "\$PIDFILE_PATH"
DRIVER

scp -q /tmp/rf-stress-driver-local.sh "${FPP_USER}@${FPP_HOST}:$DRIVER_PATH"
pi "chmod +x $DRIVER_PATH"
rm -f /tmp/rf-stress-driver-local.sh

section "Launching detached driver"
pi "setsid nohup bash $DRIVER_PATH </dev/null >>$LOG_PATH 2>&1 &
    disown" || true
sleep 2
DRIVER_PID=$(pi "cat $PIDFILE_PATH 2>/dev/null || echo ''")
if [ -z "$DRIVER_PID" ]; then
    fail "driver did not write its PID file — stress may not have started"
    pi "tail -20 $LOG_PATH 2>/dev/null"
    exit 1
fi
ok "stress launched, driver pid $DRIVER_PID"

FINISH_TIME=$(date -v+${DURATION_SECS}S '+%a %H:%M' 2>/dev/null \
    || date -d "+${DURATION_SECS} seconds" '+%a %H:%M' 2>/dev/null \
    || echo unknown)
DURATION_HUMAN="$(( DURATION_SECS / 3600 ))h $(( (DURATION_SECS / 60) % 60 ))m"

cat <<INFO

============================================================
 OVERNIGHT STRESS RUNNING
============================================================
 host           : $FPP_HOST
 driver pid     : $DRIVER_PID
 duration       : $DURATION_HUMAN  (~$DURATION_SECS s, finish ~$FINISH_TIME)
 restart cadence: every ${RESTART_INTERVAL}s (~$EXPECTED_RESTARTS total)
 settings cadnce: every ${SETTINGS_INTERVAL}s (~$EXPECTED_SETTINGS total)
 sample cadence : every ${SAMPLE_INTERVAL}s (~$EXPECTED_SAMPLES total)

 progress log : $LOG_PATH (on Pi)
 summary file : $SUMMARY_PATH (written when stress finishes)

 You can disconnect now. Driver is detached via setsid+nohup and
 will keep running through SSH disconnect and Mac sleep.

 Mid-flight check:
    $HERE/tier1-stress-overnight-check.sh
============================================================
INFO
