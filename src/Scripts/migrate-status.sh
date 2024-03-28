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

dir="$(find ./glued/Config/Migrations -not -empty -type d)"
dbmate -d ${dir} -s ${DATAPATH}/$(basename `pwd`)/schema.sql status;














#!/usr/bin/env bash

# Define directories
DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"

# ... (Other functions remain unchanged)

# Function to dynamically call check and create functions based on database type
call_function_for_database() {
  local db=$1
  local func_prefix=$2
  local func_name="${func_prefix}_${db}"

  if declare -f "$func_name" > /dev/null; then
    $func_name
  else
    echo "[ERROR] Function $func_name does not exist."
  fi
}

# Main logic to handle command-line arguments and execute dbmate in a database-specific directory
if [ -n "${1}" ]; then
  databases=("mysql" "pgsql")
  if [ "${1}" == "all" ]; then
    for db in "${databases[@]}"; do
      call_function_for_database $db "check"
      if [ -n "${2}" ]; then
        dir="./glued/Config/Migrations/${db}"
        mkdir -p "${dir}" # Ensure directory exists
        schema_file="${datapath}/$(basename `pwd`)/schema-${db}.sql"
        dbmate -d ${dir} -s "${schema_file}" new "${2}"
        echo "[PASS] Empty migration file ${2} generated in ${dir}, add relevant up/down statements using ${schema_file}."
      else
        echo "Please provide a migration name."
      fi
    done
  elif [[ " ${databases[@]} " =~ " ${1} " ]]; then
    db="${1}"
    call_function_for_database $db "check"
    if [ -n "${2}" ]; then
      dir="./glued/Config/Migrations/${db}"
      mkdir -p "${dir}" # Ensure directory exists
      schema_file="${datapath}/$(basename `pwd`)/schema-${db}.sql"
      dbmate -d ${dir} -s "${schema_file}" new "${2}"
      echo "[PASS] Empty migration file ${2} generated in ${dir}, add relevant up/down statements using ${schema_file}."
    else
      echo "Please provide a migration name."
    fi
  else
    echo "Unsupported database. Supported options are mysql, pgsql, all."
    exit 1
  fi
else
  echo "Please specify the target database (mysql, pgsql, all) and migration name."
fi

