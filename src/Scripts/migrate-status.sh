#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

# NOTE that double sourcing is needed
# otherwise .env references won't be interpreted

main() {
  if [ $# -ne 1 ]; then
    echo "[WARN] $(basename $0): Mandatory parameter missing"
    echo "Usage: $0 <pgsql|mysql>"
    exit 1
  fi

  local DB_SYSTEM="${1}"
  case "$DB_SYSTEM" in
    pgsql)
      export DATABASE_URL="${PGSQL_URL}"
      source "$DIR/migrate-test.sh" "pgsql"
      dir="$(find ./glued/Config/Pgsql -not -empty -type d)"
      if [ -z "$dir" ]; then
        echo "[WARN] No migration files in ./glued/Config/Pgsql"
        exit 1
      fi
      echo "[INFO] dbmate -d ${dir} -s ${DATAPATH}/$(basename `pwd`)/schema-pgsql.sql status"
      dbmate -d ${dir} -s ${DATAPATH}/$(basename `pwd`)/schema-pgsql.sql status
      ;;
    mysql)
      export DATABASE_URL="${MYSQL_URL}"
      source "$DIR/migrate-test.sh" "mysql"
      dir="$(find ./glued/Config/Mysql -not -empty -type d)"
      if [ -z "$dir" ]; then
        echo "[WARN] No migration files in ./glued/Config/Mysql"
        exit 1
      fi
      echo "[INFO] dbmate -d ${dir} -s ${DATAPATH}/$(basename `pwd`)/schema-mysql.sql status"
      dbmate -d ${dir} -s ${DATAPATH}/$(basename `pwd`)/schema-mysql.sql status
      ;;
    *)
      echo "[FAIL] $(basename $0): Invalid database system specified ${DB_SYSTEM}. Use 'pgsql' or 'mysql'."
      exit 1
      ;;
  esac
}

# Call the main function with all arguments passed to the script
main "$@"
echo "[DONE] $(basename $0): ${1} -----------------"
