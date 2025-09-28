#!/usr/bin/env bash
set -e

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

# NOTE that double sourcing is needed
# otherwise .env references won't be interpreted

for dbtype in Mysql Pgsql; do
  base="./glued/Config/${dbtype}"
  [ -d "$base" ] || continue   # ← skip if the dir doesn't exist

  # iterate safely over dirs (handles spaces, avoids errors)
  while IFS= read -r -d '' dir; do
    case "$dbtype" in
      Mysql) export DATABASE_URL="$MYSQL_URL" ;;
      Pgsql) export DATABASE_URL="$PGSQL_URL" ;;
    esac
    # Execute dbmate with the appropriate directory and schema file (convert dbtype to lowercase)
    dbmate -d "$dir" -s "${DATAPATH}/$(basename "$(pwd)")/schema-${dbtype,,}.sql" migrate
  done < <(find "$base" -type d -not -empty -print0)
done

echo "[DONE] $(basename "$0") -----------------"
set +e
