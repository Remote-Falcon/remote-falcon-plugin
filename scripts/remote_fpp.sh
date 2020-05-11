#!/bin/bash

echo "Starting Remote Jukebox"

REMOTE_TOKEN=$(tail /home/fpp/media/plugins/remote-falcon/remote_token.txt)
IS_REQUEST_PLAYING="false"

while [ true ]
do
playlist=$(fpp -s | cut -d',' -f7 | cut -d'.' -f1)
echo "${playlist}"
/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${REMOTE_TOKEN}\",\"playlist\":\"${playlist}\"}" https://remotefalcon.com/services/rmrghbsEvMhSH8LKuJydVn23pvsFKX/remoteFalcon/updateWhatsPlaying.php
PLAYLISTNAME=$(/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${REMOTE_TOKEN}\"}" https://remotefalcon.com/services/rmrghbsEvMhSH8LKuJydVn23pvsFKX/remoteFalcon/fetchNextPlaylistFromQueue.php)
if [ "${PLAYLISTNAME}" != "null" ]; then
	#As long as a viewer request is not currently playing, interrup any playing playlist
	echo "Received Request for ${PLAYLISTNAME}"
	STATUS=$(fpp -s | cut -d',' -f2)
	if [ -z "${STATUS}" ]; then
		echo "Error with status value" >&2
		exit 1
	fi
	case ${STATUS} in
		#Idle
		0)
			IS_REQUEST_PLAYING="true"
			echo "Starting Request for ${PLAYLISTNAME}"
			fpp -P "${PLAYLISTNAME}" ${STARTITEM}
			fpp -c graceful
			/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${REMOTE_TOKEN}\"}" https://remotefalcon.com/services/rmrghbsEvMhSH8LKuJydVn23pvsFKX/remoteFalcon/updatePlaylistQueue.php
			;;
		#Playing
		1)
			if [ "${IS_REQUEST_PLAYING}" = "false" ]; then
				IS_REQUEST_PLAYING="true"
				echo "Starting Request for ${PLAYLISTNAME}"
				fpp -P "${PLAYLISTNAME}" ${STARTITEM}
				fpp -c graceful
				/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${REMOTE_TOKEN}\"}" https://remotefalcon.com/services/rmrghbsEvMhSH8LKuJydVn23pvsFKX/remoteFalcon/updatePlaylistQueue.php
			fi
			;;
		#Stopping
		2|*)
			if [ "${IS_REQUEST_PLAYING}" = "false" ]; then
				IS_REQUEST_PLAYING="true"
				echo "Starting Request for ${PLAYLISTNAME}"
				fpp -P "${PLAYLISTNAME}" ${STARTITEM}
				fpp -c graceful
				/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${REMOTE_TOKEN}\"}" https://remotefalcon.com/services/rmrghbsEvMhSH8LKuJydVn23pvsFKX/remoteFalcon/updatePlaylistQueue.php
			fi
			;;
	esac
else
	#Reload schedule and only set viewer request playing boolean to false once idle
	STATUS=$(fpp -s | cut -d',' -f2)
	if [ -z "${STATUS}" ]; then
		echo "Error with status value" >&2
		exit 1
	fi
	case ${STATUS} in
		0)
			echo "Resuming Schedule"
			IS_REQUEST_PLAYING="false"
			fpp -R
			;;
		1)
			;;
		2|*)
			;;
	esac
fi
sleep 3
done