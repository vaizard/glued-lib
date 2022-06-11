#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"

mkdir -p "${datapath}/$(basename `pwd`)/backups";
mkdir -p "${datapath}/$(basename `pwd`)/config";
mkdir -p "${datapath}/$(basename `pwd`)/cache";
echo "[PASS] datapaths exist"
