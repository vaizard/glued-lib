#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"


echo "[NOTE] backing up! this may take a while ..."
mysqldump --lock-tables=false --single-transaction --no-data glued >> "${DATAPATH}/$(basename `pwd`)/backups/mysql-schema-$(date +'%Y%m%dT%H%M%S').sql";
mysqldump --lock-tables=false --single-transaction glued >> "${DATAPATH}/$(basename `pwd`)/backups/mysql-full-$(date +'%Y%m%dT%H%M%S').sql";
echo "[PASS] backup done"
