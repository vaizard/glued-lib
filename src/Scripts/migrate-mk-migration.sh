#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"
# NOTE that double sourcing is needed
# otherwise .env references won't be interpreted

main() {
  if [ $# -ne 2 ]; then
    echo "[WARN] $(basename $0): Mandatory parameter(s) missing"
    echo "Usage: $0 <pgsql|mysql> <migration-name>"
    exit 1
  fi

  local DB_SYSTEM="${1}"
  case "$DB_SYSTEM" in
    pgsql)
      export DATABASE_URL="${PGSQL_URL}"
      source "$DIR/migrate-test.sh" "pgsql"
      dir="$(find ./glued/Config/Pgsql -type d)"
      dbmate -d ${dir} -s ${datapath}/$(basename `pwd`)/schema-pgsql.sql new "${2}";
      echo "[PASS] Empty migration file ${2} generated, add relevant up/down statements. To get the \`CREATE TABLE\` statements, use \`migrate-dump\` and cherry-pick what you need."
      ;;
    mysql)
      export DATABASE_URL="${MYSQL_URL}"
      source "$DIR/migrate-test.sh" "mysql"
      dir="$(find ./glued/Config/Mysql -not -empty -type d)"
      dbmate -d ${dir} -s ${datapath}/$(basename `pwd`)/schema-pgsql.sql new "${2}";
      echo "[PASS] Empty migration file ${2} generated, add relevant up/down statements. To get the \`CREATE TABLE\` statements, use \`migrate-dump\` and cherry-pick what you need."
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
