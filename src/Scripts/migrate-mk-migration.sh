#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"
# NOTE that double sourcing is needed
# otherwise .env references won't be interpreted

# Function to check MySQL connection
check_mysql() {
  if ! mysql -u $MYSQL_USERNAME -p"${MYSQL_PASSWORD}" -h ${MYSQL_HOSTNAME} -e "use $MYSQL_DATABASE"; then
    echo "[WARN] Connecting to MySQL database $MYSQL_DATABASE failed."
    create_mysql
  else
    echo "[INFO] Connected to MySQL database $MYSQL_DATABASE successfully."
  fi
}

# Function to check PostgreSQL connection
check_pgsql() {
  if ! PGPASSWORD=$PGSQL_PASSWORD psql -U $PGSQL_USERNAME -h $PGSQL_HOSTNAME -d $PGSQL_DATABASE -c "SELECT 1;" > /dev/null 2>&1; then
    echo "[WARN] Connecting to PostgreSQL database $PGSQL_DATABASE failed."
    create_pgsql
  else
    echo "[INFO] Connected to PostgreSQL database $PGSQL_DATABASE successfully."
  fi
}

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
