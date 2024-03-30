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

# Connect to PostgreSQL database
if ! PGPASSWORD="${PGSQL_PASSWORD}" psql -U "${PGSQL_USERNAME}" -h "${PGSQL_HOSTNAME}" -d "${PGSQL_DATABASE}" -c "\q"; then
  echo "[WARN] Connecting to database $PGSQL_DATABASE failed."
  echo "[INFO] Attempting to create database and assign privileges."

  # Create Database
  PGPASSWORD="${PGSQL_PASSWORD}" psql -U "${PGSQL_USERNAME}" -h "${PGSQL_HOSTNAME}" -d "postgres" -c "CREATE DATABASE ${PGSQL_DATABASE} WITH ENCODING='UTF8';"

  # Create User and Grant Privileges
  PGPASSWORD="${PGSQL_PASSWORD}" psql -U "${PGSQL_USERNAME}" -h "${PGSQL_HOSTNAME}" -d "${PGSQL_DATABASE}" <<EOSQL
CREATE USER ${PGSQL_USERNAME} WITH PASSWORD '${PGSQL_PASSWORD}';
GRANT ALL PRIVILEGES ON DATABASE ${PGSQL_DATABASE} TO ${PGSQL_USERNAME};
EOSQL
fi

for dir in $(find ./glued/Config/Migrations/mysql -not -empty -type d) ; do 
  dbmate -d "${dir}" -s "${DATAPATH}/$(basename `pwd`)/mysql-schema.sql" migrate;
done;

for dir in $(find ./glued/Config/Migrations/pgsql -not -empty -type d) ; do 
  dbmate -d "${dir}" -s "${DATAPATH}/$(basename `pwd`)/pgsql-schema.sql" migrate;
done;

echo "[PASS] migrated"
