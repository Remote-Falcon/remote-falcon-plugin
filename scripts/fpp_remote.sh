#!/bin/bash

REMOTE_TOKEN=$(tail /home/fpp/media/plugins/remote-falcon/remote_token.txt)

echo falcon | sudo apt-get install sshpass
(sleep 5; echo yes; sleep 2; echo n;...) | SSHPASS='falcon' sshpass -e ssh -tt -o StrictHostKeyChecking=no fpp@$(cat /proc/sys/kernel/hostname) ssh -R 80:localhost:80 ${REMOTE_TOKEN}@ssh.localhost.run & disown
echo falcon | sudo rm -f /home/fpp/media/plugins/remote-falcon/remote_url.txt
sleep 5
SSHPASS='falcon' sshpass -e ssh -tt -o StrictHostKeyChecking=no fpp@$(cat /proc/sys/kernel/hostname) ssh -R 80:localhost:80 ${REMOTE_TOKEN}@ssh.localhost.run > /home/fpp/media/plugins/remote-falcon/remote_url.txt 2>&1 & disown
sleep 5
REMOTEURLTXT=$(echo falcon | sudo tail /home/fpp/media/plugins/remote-falcon/remote_url.txt)
sleep 3
REMOTEURL=$(echo ${REMOTEURLTXT} | cut -d ' ' -f 13- | tr -d '\040\011\012\015')
sleep 3
echo falcon | sudo /usr/bin/curl -H "Content-Type: application/json" -X POST -d "{\"remoteKey\":\"${REMOTE_TOKEN}\",\"remoteURL\":\"${REMOTEURL}\"}" https://remotefalcon.com/cgi-bin/rmrghbsEvMhSH8LKuJydVn23pvsFKX/saveRemoteByKey.php

echo "Complete!"