#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

# NOTE that double sourcing is needed
# otherwise .env references won't be interpreted
# DEBUG:

#echo $DATABASE_URL

for dir in $(find ./glued/Config/Migrations -not -empty -type d) ; do 
  # DEBUG:
  #echo "dbmate -d ${dir} -s ${datapath}/$(basename `pwd`)/schema.sql migrate"
  dbmate -d "${dir}" -s "${datapath}/$(basename `pwd`)/schema.sql" migrate;
done;

echo "[PASS] migrated"
