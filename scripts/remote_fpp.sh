#!/bin/bash

echo "Starting Remote FPP"

remoteToken=$(tail /home/fpp/media/plugins/remote-falcon/remote_token.txt)

currentlyPlayingInRF=""
viewerControlMode=$(/usr/bin/curl -H "remotetoken: ${remoteToken}" https://remotefalcon.com/remotefalcon/api/viewerControlMode | python -c "import sys, json; print json.load(sys.stdin)['viewerControlMode']")
echo "viewerControlMode: ${viewerControlMode}" 
while [ true ]
do
	currentlyPlaying=$(fpp -s | cut -d',' -f4)
	if [ "$currentlyPlaying" != "$currentlyPlayingInRF" ]; then
		echo "Updating current playing playlist to ${currentlyPlaying}"
		/usr/bin/curl -H "Content-Type: application/json" -H "remotetoken: ${remoteToken}" -X POST -d "{\"playlist\":\"${currentlyPlaying}\"}" https://remotefalcon.com/remotefalcon/api/updateWhatsPlaying
		currentlyPlayingInRF=$currentlyPlaying;
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
			if [ "${viewerControlMode}" = "voting" ]; then
				playlist=$(/usr/bin/curl -H "remotetoken: ${remoteToken}" https://remotefalcon.com/remotefalcon/api/highestVotedPlaylist | python -c "import sys, json; print json.load(sys.stdin)['winningPlaylist']")
				if [ "${playlist}" != "None" ]; then
					echo "Starting Request for ${playlist}"
					fpp -P "${playlist}"
				else
					echo "No playlist found"
				fi
			else
				playlist=$(/usr/bin/curl -H "remotetoken: ${remoteToken}" https://remotefalcon.com/remotefalcon/api/nextPlaylistInQueue | python -c "import sys, json; print json.load(sys.stdin)['nextPlaylist']")
				if [ "${playlist}" != "None" ]; then
					echo "Starting Request for ${playlist}"
					fpp -P "${playlist}"
					/usr/bin/curl -H "Content-Type: application/json" -H "remotetoken: ${remoteToken}" -X POST https://remotefalcon.com/remotefalcon/api/updatePlaylistQueue
				else
					echo "No playlist found"
				fi
			fi
			;;
	esac
	sleep 4
done