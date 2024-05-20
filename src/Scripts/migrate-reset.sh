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
      pushd ./glued/Config/Pgsql || exit 2
      find . -maxdepth 1 -type f | xargs -I {} sh -c 'filename={}; description=$(echo $filename | cut -d"_" -f2 | cut -d"." -f1); echo "DELETE FROM glued.schema_migrations WHERE version LIKE '\''$(echo $filename | cut -d"_" -f1)'\'' ESCAPE '\''#'\''; -- migration: ${description}"'
      popd
      ;;
    mysql)
      pushd ./glued/Config/Pgsql || exit 3
      find . -maxdepth 1 -type f | xargs -I {} sh -c 'filename={}; description=$(echo $filename | cut -d"_" -f2 | cut -d"." -f1); echo "DELETE FROM glued.schema_migrations WHERE version LIKE '\''$(echo $filename | cut -d"_" -f1)'\'' ESCAPE '\''#'\''; -- migration: ${description}"'
      popd
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
