#!/bin/bash
set -e

# Runs on first install, on plugin Update (FPP re-runs it after git pull when
# no fpp_upgrade.sh exists), and on Reinstall All Plugins — always as root.
# Must stay idempotent: every step below tolerates already being done.
# Per-start work belongs in postStart.sh.

. ${FPPDIR}/scripts/common

# Add required Apache CSP (Content-Security-Policy) allowed domain.
# Tolerate failure in case the entry already exists from a previous install.
${FPPDIR}/scripts/ManageApacheContentPolicy.sh add connect-src https://remotefalcon.com 2>/dev/null || true

setSetting restartFlag 1

#fpp_install
