#!/bin/bash

# Runs once when FPP first installs this plugin. NOT re-run on plugin upgrade
# (per FPP's install_plugin script), so don't put anything here that needs to
# happen on every code update.

. ${FPPDIR}/scripts/common

# Add required Apache CSP (Content-Security-Policy) allowed domain.
# Tolerate failure in case the entry already exists from a previous install.
${FPPDIR}/scripts/ManageApacheContentPolicy.sh add connect-src https://remotefalcon.com 2>/dev/null || true

# Install a logrotate config so the listener log doesn't grow unbounded
# across a multi-month show season. Symlink (not copy) so plugin upgrades
# automatically pick up any future changes to the rotation policy.
PLUGIN_DIR=/home/fpp/media/plugins/remote-falcon
LOGROTATE_SRC="${PLUGIN_DIR}/scripts/logrotate.d-remote-falcon"
LOGROTATE_DST=/etc/logrotate.d/remote-falcon
if [ -f "$LOGROTATE_SRC" ] && [ -d /etc/logrotate.d ]; then
    sudo ln -sf "$LOGROTATE_SRC" "$LOGROTATE_DST" 2>/dev/null || \
        ln -sf "$LOGROTATE_SRC" "$LOGROTATE_DST" 2>/dev/null || true
fi

setSetting restartFlag 1

#fpp_install
