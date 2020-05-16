#!/bin/bash

echo "Starting Remote FPP"

remoteToken=$(tail /home/fpp/media/plugins/remote-falcon/remote_token.txt)

echo "Starting On Demand/Jukebox Mode"
currentlyPlayingInRF=""
while [ true ]
do
	currentlyPlaying=$(fpp -s | cut -d',' -f4)
	if [ "$currentlyPlaying" != "$currentlyPlayingInRF" ]; then
		echo "Updating current playing playlist to ${currentlyPlaying}"
		/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${remoteToken}\",\"playlist\":\"${currentlyPlaying}\"}" https://remotefalcon.com/services/rmrghbsEvMhSH8LKuJydVn23pvsFKX/api/updateWhatsPlaying.php
		currentlyPlayingInRF=$currentlyPlaying
	fi
	fppSchedulePlaying=$(fpp -s | cut -d',' -f14)
	case ${fppSchedulePlaying} in
		#Schedule not playing (viewer request)
		0)
			#Go ahead and reload that schedule
			fpp -R
			;;
		#Schedule playing, fetch next playlist
		1)
			playlist=$(/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${remoteToken}\"}" https://remotefalcon.com/services/rmrghbsEvMhSH8LKuJydVn23pvsFKX/api/fetchNextPlaylistFromQueue.php | python -c "import sys, json; print json.load(sys.stdin)['data']['nextPlaylist']")
			if [ "${playlist}" != "None" ]; then
				echo "Starting Request for ${playlist}"
				fpp -P "${playlist}"
				fpp -c graceful
				/usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteToken\":\"${remoteToken}\"}" https://remotefalcon.com/services/rmrghbsEvMhSH8LKuJydVn23pvsFKX/api/updatePlaylistQueue.php
			else
				echo "No playlist found"
			fi
			;;
		*)
			#Go ahead and reload that schedule
			fpp -R
			;;
	esac
	sleep 4
done