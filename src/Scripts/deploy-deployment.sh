#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

echo "[INFO] deploying deployment"

mkdir -p "${DATAPATH}/$(basename `pwd`)/crons"
mkdir -p "${DATAPATH}/$(basename `pwd`)/config"

if [ -d "glued/Config/Deployment" ]; then
    cp -r glued/Config/Deployment/* "${DATAPATH}/$(basename `pwd`)/config"
fi

if [ -d "glued/Config/Cron" ]; then
    cp -r glued/Config/Cron/* "${DATAPATH}/$(basename "$(pwd)")/crons"
fi

