#!/usr/bin/env bash
# Shared helpers for hardware-side smoke and stability tests. These run
# from a developer host (Mac/Linux) and drive a real FPP Pi over SSH.
# Designed to be sourced by the tier-N scripts in this directory.
#
# Required env: FPP_HOST (e.g., 192.168.1.80)
# Optional env: FPP_USER (default: fpp)
#
# All scripts using this lib should snapshot the Pi's existing plugin
# tree + settings to /tmp before installing the test branch, and
# restore from the snapshot at the end so the Pi returns to whatever
# state it was in (typically a real-RF-connected production install).

: "${FPP_HOST:?FPP_HOST is required (e.g., FPP_HOST=192.168.1.80)}"
: "${FPP_USER:=fpp}"

PASS=0
FAIL=0

ok()      { printf "  \033[32m✓\033[0m %s\n" "$1"; PASS=$((PASS+1)); }
fail()    { printf "  \033[31m✗\033[0m %s\n" "$1"; FAIL=$((FAIL+1)); }
section() { printf "\n\033[1m%s\033[0m\n" "$1"; }

# Run a one-line command on the Pi as root via sudo. Use for plugin/config
# manipulation. Stderr surfaces here. Stdout returned.
pi() {
    ssh -o BatchMode=yes "${FPP_USER}@${FPP_HOST}" "$@"
}

# Snapshot the existing plugin tree and settings to /tmp on the Pi so we
# can restore state at the end of the test. Echoes the snapshot path.
pi_snapshot() {
    local stamp
    stamp=$(date +%Y%m%d-%H%M%S)
    local tar_path="/tmp/rf-plugin-snap-${stamp}.tar.gz"
    local cfg_path="/tmp/rf-plugin-cfg-${stamp}.before"
    pi "sudo tar -czf $tar_path -C /home/fpp/media/plugins remote-falcon 2>/dev/null && \
        sudo cp /home/fpp/media/config/plugin.remote-falcon $cfg_path 2>/dev/null && \
        echo $tar_path:$cfg_path"
}

# Stop any running listener (handles cross-user kill via sudo).
pi_stop_listener() {
    pi 'sudo /home/fpp/media/plugins/remote-falcon/scripts/postStop.sh 2>/dev/null || true
        # Belt-and-suspenders: pkill any stragglers as root.
        sudo pkill -f "/usr/bin/php.*remote_falcon_listener\.php" 2>/dev/null || true
        sleep 1'
}

# Install the perf branch tip (or any other ref) into the Pi's plugin dir.
# Runs git clone fresh, chowns to fpp, runs fpp_install.sh.
pi_install_branch() {
    local ref="${1:-perf/listener-tightening}"
    pi "cd /home/fpp/media/plugins && \
        sudo rm -rf remote-falcon && \
        sudo git clone --branch '$ref' --quiet \
            https://github.com/Remote-Falcon/remote-falcon-plugin.git remote-falcon && \
        sudo chown -R fpp:fpp remote-falcon && \
        sudo -u fpp bash -c 'export FPPDIR=/opt/fpp && \
            /home/fpp/media/plugins/remote-falcon/scripts/fpp_install.sh' >/dev/null 2>&1"
}

# Write a settings file safely on the Pi: unreachable RF endpoint, empty
# token, listener enabled. Use for tests that should NOT generate any RF
# traffic.
pi_seed_safe_settings() {
    pi 'sudo tee /home/fpp/media/config/plugin.remote-falcon > /dev/null <<EOF
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
EOF
        sudo chown fpp:fpp /home/fpp/media/config/plugin.remote-falcon
        sudo chmod 664 /home/fpp/media/config/plugin.remote-falcon
        sudo truncate -s 0 /home/fpp/media/logs/remote-falcon-listener.log 2>/dev/null
        sudo chown fpp:fpp /home/fpp/media/logs/remote-falcon-listener.log 2>/dev/null'
}

pi_start_listener_as_fpp() {
    pi 'sudo -u fpp /home/fpp/media/plugins/remote-falcon/scripts/postStart.sh > /dev/null 2>&1
        sleep 2'
}

pi_listener_pid() {
    pi 'cat /home/fpp/media/plugins/remote-falcon/remote_falcon_listener.pid 2>/dev/null'
}

pi_listener_alive() {
    local pid="$1"
    pi "kill -0 $pid 2>/dev/null && echo alive || echo dead"
}

# Restore from a snapshot (tar_path:cfg_path) and start a fresh listener.
# Removes the logrotate symlink/file so the Pi returns to pre-install state.
pi_restore() {
    local snap="$1"
    local tar_path="${snap%%:*}"
    local cfg_path="${snap##*:}"
    pi "sudo /home/fpp/media/plugins/remote-falcon/scripts/postStop.sh 2>/dev/null || true
        sudo pkill -f '/usr/bin/php.*remote_falcon_listener\.php' 2>/dev/null || true
        sleep 1
        sudo rm -f /etc/logrotate.d/remote-falcon
        cd /home/fpp/media/plugins && sudo rm -rf remote-falcon
        sudo tar -xzf $tar_path -C /home/fpp/media/plugins/
        sudo chown -R fpp:fpp /home/fpp/media/plugins/remote-falcon
        sudo cp $cfg_path /home/fpp/media/config/plugin.remote-falcon
        sudo chown fpp:fpp /home/fpp/media/config/plugin.remote-falcon
        sudo chmod 664 /home/fpp/media/config/plugin.remote-falcon
        sudo -u fpp /home/fpp/media/plugins/remote-falcon/scripts/postStart.sh > /dev/null 2>&1
        sleep 2"
}

summarize() {
    printf "\n\033[1mSummary:\033[0m %d passed, %d failed\n" "$PASS" "$FAIL"
    return $FAIL
}
