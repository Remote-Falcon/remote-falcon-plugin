#!/bin/bash
echo "Removing sshpass"
apt-get -y remove --purge sshpass

sleep 5

echo "Removing scripts"
rm /home/fpp/media/scripts/fpp_remote.sh
rm /home/fpp/media/scripts/remote_jukebox.sh