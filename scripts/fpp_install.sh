#!/bin/bash

# Runs once when FPP first installs this plugin. NOT re-run on plugin upgrade
# (per FPP's install_plugin script), so don't put anything here that needs to
# happen on every code update.

. ${FPPDIR}/scripts/common

# Add required Apache CSP (Content-Security-Policy) allowed domain.
# Tolerate failure in case the entry already exists from a previous install.
${FPPDIR}/scripts/ManageApacheContentPolicy.sh add connect-src https://remotefalcon.com 2>/dev/null || true

setSetting restartFlag 1

#fpp_install
