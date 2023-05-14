#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

# NOTE that double sourcing is needed
# otherwise .env references won't be interpreted

if ! mysql -u $MYSQL_USERNAME -p"${MYSQL_PASSWORD}" -h ${MYSQL_HOSTNAME} -e "use $MYSQL_DATABASE"; then
  echo "[WARN] Connecting to database $MYSQL_DATABASE failed."
  echo "[INFO] Attempting to create database and assign privileges.";
  mysql -e "CREATE DATABASE $MYSQL_DATABASE /*\!40100 DEFAULT CHARACTER SET utf8 */;"
  mysql -e "CREATE USER $MYSQL_USERNAME@$MYSQL_HOSTNAME IDENTIFIED BY '$MYSQL_PASSWORD';"
  mysql -e "GRANT ALL PRIVILEGES ON glued.* TO '$MYSQL_USERNAME'@'$MYSQL_HOSTNAME';"
  mysql -e "FLUSH PRIVILEGES;"
  exit;
fi

if [ -n "${1}" ]; then
  dir="$(find ./glued/Config/Migrations -not -empty -type d)"
  dbmate -d ${dir} -s ${datapath}/$(basename `pwd`)/schema.sql new "${1}";
  echo "[PASS] Empty migration file ${1} generated, add relevant up/down statements. To get the \`CREATE TABLE\` statements, use \`migrate-dump\` and cherry-pick what you need."
else
  echo "Please provide migration name"
fi

