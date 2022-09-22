#!/bin/bash

# Mark to reboot
BASEDIR=$(dirname $0)
cd $BASEDIR
cd ..
make "SRCDIR=${SRCDIR}"


. ${FPPDIR}/scripts/common
setSetting rebootFlag 1

#fpp_install