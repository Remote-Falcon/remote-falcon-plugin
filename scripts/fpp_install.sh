#!/bin/bash

# Mark to reboot
. ${FPPDIR}/scripts/common

# Add required Apache CSP (Content-Security-Policy allowed domains
${FPPDIR}/scripts/ManageApacheContentPolicy.sh add default-src https://remotefalcon.com

setSetting restartFlag 1

#fpp_install
