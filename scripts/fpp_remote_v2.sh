#!/bin/bash

REMOTE_TOKEN=$(tail /home/fpp/media/plugins/remote-falcon/remote_token.txt)

echo falcon | sudo apt-get install sshpass
(sleep 5; echo yes; sleep 2; echo n;...) | SSHPASS='falcon' sshpass -e ssh -tt -o StrictHostKeyChecking=no fpp@$(cat /proc/sys/kernel/hostname) ssh -R 80:localhost:80 ${REMOTE_TOKEN}@ssh.localhost.run -o ServerAliveInterval=60 & disown
echo falcon | sudo rm -f /home/fpp/media/plugins/remote-falcon/remote_url.txt
sleep 5
SSHPASS='falcon' sshpass -e ssh -tt -o StrictHostKeyChecking=no fpp@$(cat /proc/sys/kernel/hostname) ssh -R 80:localhost:80 ${REMOTE_TOKEN}@ssh.localhost.run -o ServerAliveInterval=60 > /home/fpp/media/plugins/remote-falcon/remote_url.txt 2>&1 & disown