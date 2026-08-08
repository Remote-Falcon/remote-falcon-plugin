#!/bin/bash
set -e

# Runs on first install and on Reinstall All Plugins, but NOT on a plugin-only
# upgrade — anything that must happen on every code update lives in
# postStart.sh instead. Safe to re-run: every step below tolerates already
# being done.

. ${FPPDIR}/scripts/common

# Add required Apache CSP (Content-Security-Policy) allowed domain.
# Tolerate failure in case the entry already exists from a previous install.
${FPPDIR}/scripts/ManageApacheContentPolicy.sh add connect-src https://remotefalcon.com 2>/dev/null || true

setSetting restartFlag 1

#fpp_install
