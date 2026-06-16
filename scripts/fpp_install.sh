#!/bin/bash

# Runs once when FPP first installs this plugin. NOT re-run on plugin upgrade
# (per FPP's install_plugin script), so don't put anything here that needs to
# happen on every code update.

. ${FPPDIR}/scripts/common

# Add required Apache CSP (Content-Security-Policy) allowed domain.
# Tolerate failure in case the entry already exists from a previous install.
${FPPDIR}/scripts/ManageApacheContentPolicy.sh add connect-src https://remotefalcon.com 2>/dev/null || true

# Install a logrotate config so the listener log doesn't grow unbounded
# across a multi-month show season. Copy (not symlink) and force root
# ownership — logrotate refuses to process configs whose target file is
# owned by a non-root user (security: prevents log injection). The plugin
# tree is fpp-owned, so symlinking would fail this check.
#
# Trade-off: changes to the rotate policy aren't picked up automatically
# on plugin upgrade. fpp_install.sh only runs on initial install, so users
# upgrading would need to reinstall to pick up policy changes. The policy
# is small and stable; this is acceptable.
PLUGIN_DIR=/home/fpp/media/plugins/remote-falcon
LOGROTATE_SRC="${PLUGIN_DIR}/scripts/logrotate.d-remote-falcon"
LOGROTATE_DST=/etc/logrotate.d/remote-falcon
if [ -f "$LOGROTATE_SRC" ] && [ -d /etc/logrotate.d ]; then
    install -m 0644 -o root -g root "$LOGROTATE_SRC" "$LOGROTATE_DST" 2>/dev/null || \
        sudo install -m 0644 -o root -g root "$LOGROTATE_SRC" "$LOGROTATE_DST" 2>/dev/null || true
fi

setSetting restartFlag 1

#fpp_install
