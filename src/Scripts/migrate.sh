#!/usr/bin/env bash
set -e

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

# NOTE that double sourcing is needed
# otherwise .env references won't be interpreted

# Loop over both Mysql and Pgsql directories
for dbtype in Mysql Pgsql; do
  # Find non-empty directories within each specified path
  for dir in $(find "./glued/Config/${dbtype}" -not -empty -type d); do

    # Set DATABASE_URL environment variable based on dbtype
    if [ "${dbtype}" == "Mysql" ]; then export DATABASE_URL="${MYSQL_URL}"; fi
    if [ "${dbtype}" == "Pgsql" ]; then export DATABASE_URL="${PGSQL_URL}"; fi

    # Execute dbmate with the appropriate directory and schema file (convert dbtype to lowercase)
    dbmate -d "${dir}" -s "${DATAPATH}/$(basename `pwd`)/schema-${dbtype,,}.sql" migrate
  done
done

echo "[DONE] $(basename $0) -----------------"
set +e
