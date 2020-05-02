#!/bin/bash

REMOTE_TOKEN=$(tail /home/fpp/media/plugins/remote-falcon/remote_token.txt)

while [ true ]
do
PLAYLISTNAME=$(/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${REMOTE_TOKEN}\"}" https://remotefalcon.com/cgi-bin/rmrghbsEvMhSH8LKuJydVn23pvsFKX/fetchCurrentPlaylistFromQueue.php)
PLAYLISTUPONSILENCE=$(/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${REMOTE_TOKEN}\"}" https://remotefalcon.com/cgi-bin/rmrghbsEvMhSH8LKuJydVn23pvsFKX/fetchPlaylistUponSilence.php)
if [ "${PLAYLISTNAME}" != "null" ]; then
	STATUS=$(fpp -s | cut -d',' -f2)
	if [ -z "${STATUS}" ]; then
		echo "Error with status value" >&2
		exit 1
	fi
	case ${STATUS} in
		0)
			echo "Starting ${PLAYLISTNAME}"
			fpp -P "${PLAYLISTNAME}" ${STARTITEM}
			/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${REMOTE_TOKEN}\"}" https://remotefalcon.com/cgi-bin/rmrghbsEvMhSH8LKuJydVn23pvsFKX/updatePlaylistQueue.php
			;;
		1)
			;;
		2|*)
			;;
	esac
# elif [ "${PLAYLISTUPONSILENCE}" != "" ]; then
# 	STATUS=$(fpp -s | cut -d',' -f2)
# 	if [ -z "${STATUS}" ]; then
# 		echo "Error with status value" >&2
# 		exit 1
# 	fi
# 	case ${STATUS} in
# 		0)
# 			echo "Starting ${PLAYLISTUPONSILENCE}"
# 			fpp -P "${PLAYLISTUPONSILENCE}" ${STARTITEM}
# 			;;
# 		1)
# 			;;
# 		2|*)
# 			;;
# 	esac
fi
sleep 5
done