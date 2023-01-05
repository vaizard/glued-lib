#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

# NOTE that double sourcing is needed
# otherwise .env references won't be interpreted
# DEBUG:

#echo $DATABASE_URL

if ! mysql -u $MYSQL_USERNAME -p"${MYSQL_PASSWORD}" -h ${MYSQL_HOSTNAME} -e "use $MYSQL_DATABASE"; then
  echo "[WARN] Connecting to database $MYSQL_DATABASE failed."
  echo "[INFO] Attempting to create database and assign privileges.";
  mysql -e "CREATE DATABASE $MYSQL_DATABASE /*\!40100 DEFAULT CHARACTER SET utf8 */;"
  mysql -e "CREATE USER $MYSQL_USERNAME@$MYSQL_HOSTNAME IDENTIFIED BY '$MYSQL_PASSWORD';"
  mysql -e "GRANT ALL PRIVILEGES ON glued.* TO '$MYSQL_USERNAME'@'$MYSQL_HOSTNAME';"
  mysql -e "FLUSH PRIVILEGES;"
  exit;
fi


for dir in $(find ./glued/Config/Migrations -not -empty -type d) ; do 
  # DEBUG:
  #echo "dbmate -d ${dir} -s ${DATAPATH}/$(basename `pwd`)/schema.sql migrate"
  dbmate -d "${dir}" -s "${DATAPATH}/$(basename `pwd`)/schema.sql" migrate;
done;

echo "[PASS] migrated"
