#!/bin/bash

# Mark to reboot
sed -i -e "s/^rebootFlag .*/rebootFlag = 1/" /home/fpp/media/settings

#fpp_install