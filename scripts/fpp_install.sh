#!/bin/bash
sudo apt -y update && sudo apt -y upgrade && curl -fsSL https://deb.nodesource.com/setup_16.x | sudo -E bash - && sudo apt -y install nodejs
#fpp_install