#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

# NOTE that double sourcing is needed
# otherwise .env references won't be interpreted
# DEBUG:

#echo $DATABASE_URL

if ! mysql -u $mysql_username -p"${mysql_password}" -h ${mysql_hostname} -e "use $mysql_database"; then
  echo "[WARN] Connecting to database $mysql_database failed."
  echo "[INFO] Attempting to create database and assign privileges.";
  mysql -e "CREATE DATABASE $mysql_database /*\!40100 DEFAULT CHARACTER SET utf8 */;"
  mysql -e "CREATE USER $mysql_username@$mysql_hostname IDENTIFIED BY '$mysql_password';"
  mysql -e "GRANT ALL PRIVILEGES ON glued.* TO '$mysql_username'@'$mysql_hostname';"
  mysql -e "FLUSH PRIVILEGES;"
  exit;
fi


for dir in $(find ./glued/Config/Migrations -not -empty -type d) ; do 
  # DEBUG:
  #echo "dbmate -d ${dir} -s ${datapath}/$(basename `pwd`)/schema.sql migrate"
  dbmate -d "${dir}" -s "${datapath}/$(basename `pwd`)/schema.sql" migrate;
done;

echo "[PASS] migrated"
