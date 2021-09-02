#!/bin/bash

# Mark to reboot
sed -i -e "s/^restartFlag .*/restartFlag = 1/" ${FPPHOME}/media/settings

#fpp_install