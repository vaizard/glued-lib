#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

echo "[INFO] deploying deployment"

cp -r glued/Config/Deployment/*.yaml "${DATAPATH}/$(basename `pwd`)/config"
mkdir "${DATAPATH}/$(basename `pwd`)/crons"
cp -r glued/Config/Cron/* "${DATAPATH}/$(basename `pwd`)/crons"

