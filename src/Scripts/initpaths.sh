#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"

mkdir -p "${datapath}/$(basename `pwd`)/backups";
mkdir -p "${datapath}/$(basename `pwd`)/config";
mkdir -p "${datapath}/$(basename `pwd`)/cache";
mkdir -p "${datapath}/$(basename `pwd`)/share";
mkdir -p "${datapath}/$(basename `pwd`)/data";

chown www-data:www-data "${datapath}/$(basename `pwd`)/data"
chown www-data:www-data "${datapath}/$(basename `pwd`)/cache"
chmod 777 "${datapath}/$(basename `pwd`)/share"
echo "[PASS] datapaths exist"
