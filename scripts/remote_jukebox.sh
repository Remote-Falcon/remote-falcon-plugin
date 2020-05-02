#!/bin/bash

REMOTE_TOKEN=$(tail /home/fpp/media/plugins/remote-falcon/remote_token.txt)
NORMAL_SCHEDULE = "true"

while [ true ]
do
PLAYLISTNAME=$(/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${REMOTE_TOKEN}\"}" https://remotefalcon.com/cgi-bin/rmrghbsEvMhSH8LKuJydVn23pvsFKX/fetchCurrentPlaylistFromQueue.php)

STATUS=$(fpp -s | cut -d',' -f2)
if [ -z "${STATUS}" ]; then
	echo "Error with status value" >&2
	exit 1
fi

case ${STATUS} in
	#Idle
	0)
		"${NORMAL_SCHEDULE}" = "true"
		if [ "${PLAYLISTNAME}" != "null" ]; then
			"${NORMAL_SCHEDULE}" = "false"
			echo "Starting ${PLAYLISTNAME}"
			fpp -P "${PLAYLISTNAME}" ${STARTITEM}
			/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${REMOTE_TOKEN}\"}" https://remotefalcon.com/cgi-bin/rmrghbsEvMhSH8LKuJydVn23pvsFKX/updatePlaylistQueue.php
		fi
		;;
	#Playing
	1)
		if [ "${NORMAL_SCHEDULE}" = "true" && "${PLAYLISTNAME}" != "null" ]; then
			"${NORMAL_SCHEDULE}" = "false"
			echo "Starting ${PLAYLISTNAME}"
			fpp -P "${PLAYLISTNAME}" ${STARTITEM}
			/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${REMOTE_TOKEN}\"}" https://remotefalcon.com/cgi-bin/rmrghbsEvMhSH8LKuJydVn23pvsFKX/updatePlaylistQueue.php
		elif [ "${NORMAL_SCHEDULE}" = "false" && "${PLAYLISTNAME}" == "null" ]; then
			echo "Resuming Schedule"
			"${NORMAL_SCHEDULE}" = "true"
			fpp -c graceful
		fi
		;;
	#Stopping Gracefully
	2|*)
		;;
	esac
sleep 5
done