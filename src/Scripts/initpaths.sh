#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

# microservice's backups
mkdir -p "${DATAPATH}/$(basename `pwd`)/backups";

# microservice's config patches
mkdir -p "${DATAPATH}/$(basename `pwd`)/config";
chown www-data:www-data "${DATAPATH}/$(basename `pwd`)/config"

# microservice's buildtime and runtime caches
mkdir -p "${DATAPATH}/$(basename `pwd`)/cache";
chown www-data:www-data "${DATAPATH}/$(basename `pwd`)/cache"

# microservice's 'shared folder' interface
mkdir -p "${DATAPATH}/$(basename `pwd`)/share";
chmod 777 "${DATAPATH}/$(basename `pwd`)/share"

# microservice's own data (outside the database)
mkdir -p "${DATAPATH}/$(basename `pwd`)/data";
chown www-data:www-data "${DATAPATH}/$(basename `pwd`)/data"

echo "[PASS] datapaths exist"

