#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

# NOTE that double sourcing is needed
# otherwise .env references won't be interpreted

check_dependency() {
  local dependency=$1
  if ! command -v $dependency &> /dev/null; then
    echo "$dependency command not found."
    exit 1
  fi
}

create_pgsql_database() {
  export PGPASSWORD="${PGSQL_PASSWORD}"
  if ! psql -U "${PGSQL_USERNAME}" -h "${PGSQL_HOSTNAME}" -lqt | cut -d \| -f 1 | grep -qw "${PGSQL_DATABASE}"; then
    echo "[INFO] Database ${PGSQL_DATABASE} does not exist. Attempting to create."
    psql -U "${PGSQL_USERNAME}" -h "${PGSQL_HOSTNAME}" -d "postgres" -c "CREATE DATABASE ${PGSQL_DATABASE} WITH ENCODING='UTF8';"
  fi

  if ! psql -U "${PGSQL_USERNAME}" -h "${PGSQL_HOSTNAME}" -d "postgres" -tAc "SELECT 1 FROM pg_roles WHERE rolname='${PGSQL_USERNAME}'" | grep -qw "1"; then
    echo "[INFO] User ${PGSQL_USERNAME} does not exist. Attempting to create."
    psql -U "${PGSQL_USERNAME}" -h "${PGSQL_HOSTNAME}" -d "postgres" -c "CREATE USER ${PGSQL_USERNAME} WITH PASSWORD '${PGSQL_PASSWORD}';"
  fi

  echo "[INFO] Granting all privileges on database ${PGSQL_DATABASE} to user ${PGSQL_USERNAME}."
  psql -U "${PGSQL_USERNAME}" -h "${PGSQL_HOSTNAME}" -d "${PGSQL_DATABASE}" -c "GRANT ALL PRIVILEGES ON DATABASE ${PGSQL_DATABASE} TO ${PGSQL_USERNAME};"
  unset $PGPASSWORD
}

create_mysql_database() {
  if ! mysql -u $MYSQL_USERNAME -p"${MYSQL_PASSWORD}" -h ${MYSQL_HOSTNAME} -e "use $MYSQL_DATABASE"; then
    echo "[WARN] Connecting to database $MYSQL_DATABASE failed."
    echo "[INFO] Attempting to create database and assign privileges."
    mysql -e "CREATE DATABASE $MYSQL_DATABASE /*\!40100 DEFAULT CHARACTER SET utf8 */;"
    mysql -e "CREATE USER $MYSQL_USERNAME@$MYSQL_HOSTNAME IDENTIFIED BY '$MYSQL_PASSWORD';"
    mysql -e "GRANT ALL PRIVILEGES ON $MYSQL_DATABASE.* TO '$MYSQL_USERNAME'@'$MYSQL_HOSTNAME';"
    mysql -e "FLUSH PRIVILEGES;"
  fi
}

main() {
  if [ $# -ne 1 ]; then
    echo "Usage: $0 <pgsql|mysql>"
    exit 1
  fi

  local DB_SYSTEM=$1

  case $DB_SYSTEM in
    pgsql)
      check_dependency "psql"
      create_pgsql_database
      ;;
    mysql)
      check_dependency "mysql"
      create_mysql_database
      ;;
    *)
      echo "Invalid database system specified. Use 'pgsql' or 'mysql'."
      exit 1
      ;;
  esac

  echo "[INFO] Script execution completed."
}

# Call the main function with all arguments passed to the script
main "$@"


echo [PASS] "$1" database connection OK