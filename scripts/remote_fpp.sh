#!/bin/bash

echo "Starting Remote FPP"

remoteToken=$(tail /home/fpp/media/plugins/remote-falcon/remote_token.txt)

currentlyPlayingInRF=""
viewerControlMode=$(/usr/bin/curl -H "remotetoken: ${remoteToken}" https://remotefalcon.me/remotefalcon/api/viewerControlMode | python -c "import sys, json; print json.load(sys.stdin)['viewerControlMode']")
while [ true ]
do
	currentlyPlaying=$(fpp -s | cut -d',' -f4)
	if [ "$currentlyPlaying" != "$currentlyPlayingInRF" ]; then
		echo "Updating current playing playlist to ${currentlyPlaying}"
		/usr/bin/curl -H "Content-Type: application/json" -H "remotetoken: ${remoteToken}" -X POST -d "{\"playlist\":\"${currentlyPlaying}\"}" https://remotefalcon.me/remotefalcon/api/updateWhatsPlaying
		currentlyPlayingInRF=$currentlyPlaying;
	fi
	fppSchedulePlaying=$(fpp -s | cut -d',' -f14)
	case ${fppSchedulePlaying} in
		1)
      if [ "${viewerControlMode}" = "voting" ]; then
        playlist=$(/usr/bin/curl -H "remotetoken: ${remoteToken}" https://remotefalcon.me/remotefalcon/api/highestVotedPlaylist | python -c "import sys, json; print json.load(sys.stdin)['winningPlaylist']")
        if [ "${playlist}" != "None" ]; then
          echo "Starting Request for ${playlist}"
          playlistEscaped=$( printf "%s\n" "$playlist" | sed 's/ /%20/g' )
          echo $(/usr/bin/curl "http://localhost/api/command/Insert%20Playlist%20Immediate/${playlistEscaped}")
        fi
      else
        playlist=$(/usr/bin/curl -H "remotetoken: ${remoteToken}" https://remotefalcon.me/remotefalcon/api/nextPlaylistInQueue | python -c "import sys, json; print json.load(sys.stdin)['nextPlaylist']")
        if [ "${playlist}" != "None" ]; then
          echo "Starting Request for ${playlist}"
          playlistEscaped=$( printf "%s\n" "$playlist" | sed 's/ /%20/g' )
          echo $(/usr/bin/curl "http://localhost/api/command/Insert%20Playlist%20Immediate/${playlistEscaped}")
          /usr/bin/curl -H "Content-Type: application/json" -H "remotetoken: ${remoteToken}" -X POST https://remotefalcon.me/remotefalcon/api/updatePlaylistQueue
        fi
      fi
			;;
	esac
  if [ "${fppSchedulePlaying}" = 1 ]; then
	  sleep 5
  fi
done