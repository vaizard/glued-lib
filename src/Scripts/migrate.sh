#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"

for dir in $(find ./glued/Config/Migrations -not -empty -type d) ; do dbmate -d "${dir}" -s "${datapath}/$(basename `pwd`)/config" migrate; done;
echo "[PASS] migrated"
