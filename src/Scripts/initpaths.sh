#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

# microservice's backups
mkdir -p "${datapath}/$(basename `pwd`)/backups";

# microservice's config patches
mkdir -p "${datapath}/$(basename `pwd`)/config";
chown www-data:www-data "${datapath}/$(basename `pwd`)/config"

# microservice's buildtime and runtime caches
mkdir -p "${datapath}/$(basename `pwd`)/cache";
chown www-data:www-data "${datapath}/$(basename `pwd`)/cache"

# microservice's 'shared folder' interface
mkdir -p "${datapath}/$(basename `pwd`)/share";
chmod 777 "${datapath}/$(basename `pwd`)/share"

# microservice's own data (outside the database)
mkdir -p "${datapath}/$(basename `pwd`)/data";
chown www-data:www-data "${datapath}/$(basename `pwd`)/data"

echo "[PASS] datapaths exist"

