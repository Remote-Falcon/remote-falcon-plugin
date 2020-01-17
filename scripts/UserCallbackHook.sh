#!/bin/bash
################################################################################
# UserCallbackHook.sh - Callback script to allow hooking into FPP system scripts
################################################################################

REMOTE_FPP_ENABLED=$(tail /home/fpp/media/plugins/remote-falcon/remote_fpp_enabled.txt)
REMOTE_JUKEBOX_ENABLED=$(tail /home/fpp/media/plugins/remote-falcon/remote_jukebox_enabled.txt)

case $1 in
	boot)
		######################################################################
		# boot is executed at OS boot time before the network is initialized.
		# This section runs once per system boot.
		######################################################################
		# put your commands here
		;;

	preStart)
		######################################################################
		# preStart is executed before plugin preStarts and before fppd startup.
		# This section runs every time fppd is started
		######################################################################
		# put your commands here
		if [ "$REMOTE_FPP_ENABLED" = "true" ]; then
			/usr/bin/curl "http://fpp/runEventScript.php?scriptName=fpp_remote.sh" &
		fi
    if [ "$REMOTE_JUKEBOX_ENABLED" = "true" ]; then
			/usr/bin/curl "http://fpp/runEventScript.php?scriptName=remote_jukebox.sh" &
		fi
		;;

	postStart)
		######################################################################
		# postStart is executed after fppd and plugin postStarts are run.
		# This section runs every time fppd is started
		######################################################################
		# put your commands here
		;;

	preStop)
		######################################################################
		# preStop is executed before plugin preStop and run and fppd is stopped.
		# This section runs every time fppd is started
		######################################################################
		# put your commands here
		;;

	postStop)
		######################################################################
		# postStop is executed after the plugin postStops are fppd shutdown.
		# This section runs every time fppd is started
		######################################################################
		# put your commands here
		;;

esac