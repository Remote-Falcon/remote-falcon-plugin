#!/bin/bash

# Exec "$@" with only stdin/stdout/stderr open. Use for every background
# process the plugin starts. FPP commands run as children of fppd, so they
# inherit fppd's sockets (DDP 4048, E1.31 5568, its command socket). A
# process still holding them when fppd restarts in place ("Restart FPPD")
# makes the new fppd fail its bind and exit until a reboot.
#
# Exec keeps the PID, so callers can record $! as the target's PID.
# The brace group is parsed before it runs: closing fd 255 (bash's handle on
# this file) would otherwise cut off the rest of the script.
{
    for fd in /dev/fd/*; do
        fd=${fd##*/}
        [ "$fd" -gt 2 ] 2>/dev/null && eval "exec ${fd}>&-"
    done
    exec "$@"
}
