#!/usr/bin/env bash
# Tier 1 #4 — overnight soak (8 hours by default).
#
# Launches a long-running test driver on the Pi that:
#   - snapshots Pi state to /tmp
#   - installs the perf branch
#   - seeds safe settings (empty token, unreachable RF) so no real traffic
#   - starts the listener
#   - samples listener health every SOAK_SAMPLE_SECS for SOAK_HOURS hours
#   - stops the listener and restores the snapshot at the end
#   - writes a summary file with pass/fail thresholds + raw metrics
#
# The driver is launched detached via setsid+nohup so it survives SSH
# disconnect and Mac sleep. Once you see "soak launched", you can close
# this terminal and go to bed.
#
# In the morning:
#   ./tier1-soak-overnight-check.sh      — formats progress + summary
#   ssh fpp@$IP cat /tmp/rf-soak-overnight-summary.txt   — raw summary
#
# The summary is the part to inspect: PID-stable count, RSS growth, log
# growth, FPP reachability over the full window.
#
# Usage:
#   FPP_HOST=192.168.1.80 ./tier1-soak-overnight.sh
# Env:
#   SOAK_HOURS=8           total run length
#   SOAK_SAMPLE_SECS=60    sampling interval
#   TEST_BRANCH=perf/listener-tightening   branch to install

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

: "${SOAK_HOURS:=8}"
: "${SOAK_SAMPLE_SECS:=60}"
: "${TEST_BRANCH:=perf/listener-tightening}"

# SOAK_SECS, if set, overrides SOAK_HOURS — useful for short smoke runs
# of the soak harness itself.
if [ -n "${SOAK_SECS:-}" ]; then
    DURATION_SECS=$SOAK_SECS
else
    DURATION_SECS=$(( SOAK_HOURS * 3600 ))
fi
EXPECTED_SAMPLES=$(( DURATION_SECS / SOAK_SAMPLE_SECS ))

DRIVER_PATH=/tmp/rf-soak-overnight-driver.sh
LOG_PATH=/tmp/rf-soak-overnight.log
SUMMARY_PATH=/tmp/rf-soak-overnight-summary.txt
PIDFILE_PATH=/tmp/rf-soak-overnight-driver.pid

section "Tier 1 #4 — overnight soak (${SOAK_HOURS}h, sample every ${SOAK_SAMPLE_SECS}s)"

# Bail if a previous soak is still running on the Pi.
EXISTING=$(pi "test -f $PIDFILE_PATH && cat $PIDFILE_PATH || true")
if [ -n "$EXISTING" ] && pi "kill -0 $EXISTING 2>/dev/null"; then
    fail "A soak driver is already running on the Pi (PID $EXISTING)."
    echo "  If you want to abort it: ssh fpp@$FPP_HOST 'sudo kill $EXISTING'"
    echo "  Then remove: $PIDFILE_PATH"
    exit 1
fi

# Wipe any old logs/summary from a prior run so the morning view is clean.
pi "rm -f $LOG_PATH $SUMMARY_PATH $PIDFILE_PATH" > /dev/null

# Pre-flight: confirm the Pi is reachable, FPP is up, and the chosen branch
# exists. Better to fail loudly now than at 1am inside the driver.
section "Pre-flight"
PROBE=$(pi "curl -sf -o /dev/null -w '%{http_code}' http://127.0.0.1/api/system/status || echo 000")
if [ "$PROBE" = "200" ]; then
    ok "FPP /api/system/status reachable on Pi"
else
    fail "FPP not reachable (got $PROBE) — aborting before doing damage"
    exit 1
fi

GIT_PROBE=$(pi "git ls-remote --heads https://github.com/Remote-Falcon/remote-falcon-plugin.git '$TEST_BRANCH' | wc -l" | tr -d ' \n\r')
if [ "$GIT_PROBE" = "1" ]; then
    ok "branch '$TEST_BRANCH' exists on origin"
else
    fail "branch '$TEST_BRANCH' not found on origin (need to git push?)"
    exit 1
fi

section "Building remote driver"

# Build the driver script and ship it to the Pi. Heredoc uses a quoted
# delimiter so $vars are expanded HERE on the dev host (interval, branch,
# duration are baked in); inside the driver \$vars stay live for the Pi.
cat > /tmp/rf-soak-driver-local.sh <<DRIVER
#!/bin/bash
set -uo pipefail

DURATION_SECS=$DURATION_SECS
SAMPLE_SECS=$SOAK_SAMPLE_SECS
TEST_BRANCH="$TEST_BRANCH"
LOG_PATH="$LOG_PATH"
SUMMARY_PATH="$SUMMARY_PATH"
PIDFILE_PATH="$PIDFILE_PATH"

PLUGIN_DIR=/home/fpp/media/plugins/remote-falcon
LISTENER_LOG=/home/fpp/media/logs/remote-falcon-listener.log
PLUGIN_PIDFILE=\$PLUGIN_DIR/remote_falcon_listener.pid

log_d() {
    echo "[\$(date -Iseconds)] \$1" >> "\$LOG_PATH"
}

# Echo our own PID so the launcher can track us.
echo \$\$ > "\$PIDFILE_PATH"

START_EPOCH=\$(date +%s)
log_d "soak driver starting (pid \$\$, duration \${DURATION_SECS}s, branch \$TEST_BRANCH)"

# --- Setup phase ---
STAMP=\$(date +%Y%m%d-%H%M%S)
SNAP_TAR=/tmp/rf-soak-snap-\${STAMP}.tar.gz
SNAP_CFG=/tmp/rf-soak-cfg-\${STAMP}.before
sudo tar -czf "\$SNAP_TAR" -C /home/fpp/media/plugins remote-falcon 2>/dev/null
sudo cp /home/fpp/media/config/plugin.remote-falcon "\$SNAP_CFG" 2>/dev/null
log_d "snapshot: \$SNAP_TAR + \$SNAP_CFG"

sudo "\$PLUGIN_DIR/scripts/postStop.sh" >/dev/null 2>&1 || true
sleep 1

# Reinstall perf branch
cd /home/fpp/media/plugins
sudo rm -rf remote-falcon
if ! sudo git clone --branch "\$TEST_BRANCH" --quiet \
        https://github.com/Remote-Falcon/remote-falcon-plugin.git remote-falcon; then
    log_d "ERROR: git clone failed; aborting"
    echo "FAIL — git clone of \$TEST_BRANCH failed at setup" > "\$SUMMARY_PATH"
    exit 1
fi
sudo chown -R fpp:fpp remote-falcon
sudo -u fpp bash -c 'export FPPDIR=/opt/fpp && /home/fpp/media/plugins/remote-falcon/scripts/fpp_install.sh' >/dev/null 2>&1
log_d "perf branch installed"

# Seed safe settings — empty token, unreachable RF endpoint, listener
# enabled. fppStatusCheckTime=1 so idle backoff has room to be visible.
sudo tee /home/fpp/media/config/plugin.remote-falcon > /dev/null <<'CFG'
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
sudo chown fpp:fpp /home/fpp/media/config/plugin.remote-falcon
sudo chmod 664 /home/fpp/media/config/plugin.remote-falcon
sudo truncate -s 0 "\$LISTENER_LOG" 2>/dev/null
sudo chown fpp:fpp "\$LISTENER_LOG" 2>/dev/null
log_d "safe settings seeded; listener log truncated"

# Start the listener
sudo -u fpp "\$PLUGIN_DIR/scripts/postStart.sh" >/dev/null 2>&1
sleep 3
INITIAL_PID=\$(cat "\$PLUGIN_PIDFILE" 2>/dev/null || echo "")
if [ -z "\$INITIAL_PID" ] || ! sudo test -d "/proc/\$INITIAL_PID"; then
    log_d "ERROR: listener didn't start (pid=\$INITIAL_PID)"
    echo "FAIL — listener failed to start" > "\$SUMMARY_PATH"
    exit 1
fi
log_d "listener started, pid=\$INITIAL_PID"

INITIAL_RSS=\$(awk '{print \$1}' < <(ps -o rss= -p "\$INITIAL_PID" 2>/dev/null) | tr -d ' ')
INITIAL_VSZ=\$(awk '{print \$1}' < <(ps -o vsz= -p "\$INITIAL_PID" 2>/dev/null) | tr -d ' ')
INITIAL_LOG_LINES=\$(sudo wc -l < "\$LISTENER_LOG" 2>/dev/null | tr -d ' ' || echo 0)
log_d "initial RSS=\${INITIAL_RSS}KB VSZ=\${INITIAL_VSZ}KB log_lines=\${INITIAL_LOG_LINES}"

# --- Sample loop ---
PID_RESPAWNS=0
FPP_API_OK=0
FPP_API_FAIL=0
SAMPLES_TAKEN=0

END_EPOCH=\$(( START_EPOCH + DURATION_SECS ))
while [ "\$(date +%s)" -lt "\$END_EPOCH" ]; do
    SAMPLES_TAKEN=\$(( SAMPLES_TAKEN + 1 ))
    NOW=\$(date +%s)
    ELAPSED=\$(( NOW - START_EPOCH ))

    CURRENT_PID=\$(cat "\$PLUGIN_PIDFILE" 2>/dev/null || echo "")
    if [ -z "\$CURRENT_PID" ] || ! sudo test -d "/proc/\$CURRENT_PID"; then
        log_d "WARN sample=\$SAMPLES_TAKEN listener pid \$CURRENT_PID dead"
        CURRENT_RSS=0
        CURRENT_VSZ=0
    else
        CURRENT_RSS=\$(awk '{print \$1}' < <(ps -o rss= -p "\$CURRENT_PID" 2>/dev/null) | tr -d ' ')
        CURRENT_VSZ=\$(awk '{print \$1}' < <(ps -o vsz= -p "\$CURRENT_PID" 2>/dev/null) | tr -d ' ')
    fi

    if [ -n "\$CURRENT_PID" ] && [ "\$CURRENT_PID" != "\$INITIAL_PID" ]; then
        PID_RESPAWNS=\$(( PID_RESPAWNS + 1 ))
    fi

    LOG_LINES=\$(sudo wc -l < "\$LISTENER_LOG" 2>/dev/null | tr -d ' ' || echo 0)

    if curl -sf -o /dev/null --max-time 5 http://127.0.0.1/api/system/status; then
        FPP_API_OK=\$(( FPP_API_OK + 1 ))
        FPP_OK=1
    else
        FPP_API_FAIL=\$(( FPP_API_FAIL + 1 ))
        FPP_OK=0
    fi

    log_d "sample=\$SAMPLES_TAKEN elapsed=\${ELAPSED}s pid=\$CURRENT_PID rss=\${CURRENT_RSS:-0}KB vsz=\${CURRENT_VSZ:-0}KB log_lines=\$LOG_LINES fpp_api=\$FPP_OK"

    sleep "\$SAMPLE_SECS"
done

log_d "soak window complete; collecting final metrics"

FINAL_PID=\$(cat "\$PLUGIN_PIDFILE" 2>/dev/null || echo "")
FINAL_RSS=\$(awk '{print \$1}' < <(ps -o rss= -p "\$FINAL_PID" 2>/dev/null) | tr -d ' ')
FINAL_LOG_LINES=\$(sudo wc -l < "\$LISTENER_LOG" 2>/dev/null | tr -d ' ' || echo 0)
ERROR_LINES=\$(sudo grep -cE 'ERROR|FATAL|WARNING' "\$LISTENER_LOG" 2>/dev/null | tr -d ' ' || echo 0)

# --- Tear-down: stop listener and restore snapshot ---
log_d "stopping listener"
sudo "\$PLUGIN_DIR/scripts/postStop.sh" >/dev/null 2>&1 || true
sleep 1

log_d "restoring snapshot"
sudo rm -f /etc/logrotate.d/remote-falcon
cd /home/fpp/media/plugins
sudo rm -rf remote-falcon
sudo tar -xzf "\$SNAP_TAR" -C /home/fpp/media/plugins/
sudo chown -R fpp:fpp remote-falcon
sudo cp "\$SNAP_CFG" /home/fpp/media/config/plugin.remote-falcon
sudo chown fpp:fpp /home/fpp/media/config/plugin.remote-falcon
sudo chmod 664 /home/fpp/media/config/plugin.remote-falcon
sudo -u fpp "\$PLUGIN_DIR/scripts/postStart.sh" >/dev/null 2>&1
sleep 2
RESTORED_PID=\$(cat "\$PLUGIN_PIDFILE" 2>/dev/null || echo "")
log_d "snapshot restored; production listener pid=\$RESTORED_PID"

# --- Write summary ---
RSS_DELTA=\$(( FINAL_RSS - INITIAL_RSS ))
LOG_DELTA=\$(( FINAL_LOG_LINES - INITIAL_LOG_LINES ))
WALL_SECS=\$(( \$(date +%s) - START_EPOCH ))

if [ "\$PID_RESPAWNS" -eq 0 ] \
        && [ "\$FPP_API_FAIL" -lt 5 ] \
        && [ "\$RSS_DELTA" -lt 5000 ]; then
    VERDICT="PASS"
else
    VERDICT="FAIL"
fi

cat > "\$SUMMARY_PATH" <<SUMMARY
=== Remote Falcon overnight soak — \$VERDICT ===
duration            : \${WALL_SECS}s (\$(( WALL_SECS / 3600 ))h \$(( (WALL_SECS / 60) % 60 ))m)
samples             : \$SAMPLES_TAKEN (expected ~$EXPECTED_SAMPLES)
branch              : \$TEST_BRANCH

listener pid (start): \$INITIAL_PID
listener pid (end)  : \$FINAL_PID
respawn samples     : \$PID_RESPAWNS  (anything > 0 means the listener died and the PID file changed mid-soak)

memory:
  RSS start         : \${INITIAL_RSS} KB
  RSS end           : \${FINAL_RSS} KB
  RSS growth        : \${RSS_DELTA} KB

log activity:
  log lines start   : \$INITIAL_LOG_LINES
  log lines end     : \$FINAL_LOG_LINES
  growth            : \$LOG_DELTA lines over \${WALL_SECS}s
  ERROR/FATAL/WARN  : \$ERROR_LINES

FPP /api/system/status reachability:
  ok samples        : \$FPP_API_OK
  fail samples      : \$FPP_API_FAIL

production listener restored: pid=\$RESTORED_PID

thresholds for PASS:
  - PID respawns < 1            (got \$PID_RESPAWNS)
  - FPP API fails < 5           (got \$FPP_API_FAIL)
  - RSS growth < 5000 KB        (got \${RSS_DELTA} KB)

raw progress log: \$LOG_PATH
SUMMARY

log_d "summary written to \$SUMMARY_PATH (\$VERDICT)"
rm -f "\$PIDFILE_PATH"
DRIVER

scp -q /tmp/rf-soak-driver-local.sh "${FPP_USER}@${FPP_HOST}:$DRIVER_PATH"
pi "chmod +x $DRIVER_PATH"
rm -f /tmp/rf-soak-driver-local.sh

# --- Launch detached on Pi ---
section "Launching detached driver"
pi "setsid nohup bash $DRIVER_PATH </dev/null >>$LOG_PATH 2>&1 &
    disown" || true
sleep 2
DRIVER_PID=$(pi "cat $PIDFILE_PATH 2>/dev/null || echo ''")
if [ -z "$DRIVER_PID" ]; then
    fail "driver did not write its PID file — soak may not have started"
    pi "tail -20 $LOG_PATH 2>/dev/null"
    exit 1
fi
ok "soak launched, driver pid $DRIVER_PID"

FINISH_TIME=$(date -v+${DURATION_SECS}S '+%a %H:%M' 2>/dev/null \
    || date -d "+${DURATION_SECS} seconds" '+%a %H:%M' 2>/dev/null \
    || echo unknown)
DURATION_HUMAN="$(( DURATION_SECS / 3600 ))h $(( (DURATION_SECS / 60) % 60 ))m"

cat <<INFO

============================================================
 OVERNIGHT SOAK RUNNING
============================================================
 host       : $FPP_HOST
 driver pid : $DRIVER_PID
 duration   : $DURATION_HUMAN  (~$DURATION_SECS s, finish ~$FINISH_TIME)
 samples    : every ${SOAK_SAMPLE_SECS}s (~$EXPECTED_SAMPLES total)

 progress log : $LOG_PATH (on Pi)
 summary file : $SUMMARY_PATH (written when soak finishes)

 You can disconnect now. The driver is detached via setsid+nohup and
 will keep running through SSH disconnect and Mac sleep.

 In the morning:
    $HERE/tier1-soak-overnight-check.sh
============================================================
INFO
