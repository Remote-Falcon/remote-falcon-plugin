#!/bin/bash
set -e

# Runs on first install, on plugin Update (FPP re-runs it after git pull when
# no fpp_upgrade.sh exists), and on Reinstall All Plugins — always as root.
# Must stay idempotent: every step below tolerates already being done.
# Per-start work belongs in postStart.sh.

. ${FPPDIR}/scripts/common

# Earlier versions whitelisted connect-src https://remotefalcon.com in FPP's
# Apache CSP for browser-side API calls. Those calls now run server-side
# (issue #157), so the entry only widens FPP's CSP for nothing (#194). FPP
# re-runs this script on upgrade, so remove it here to clean existing
# installs. The script only exists on FPP 9+; tolerate failure when the
# entry is already gone.
if [ -x "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" ]; then
    ${FPPDIR}/scripts/ManageApacheContentPolicy.sh remove connect-src https://remotefalcon.com 2>/dev/null || true
fi

setSetting restartFlag 1

#fpp_install
