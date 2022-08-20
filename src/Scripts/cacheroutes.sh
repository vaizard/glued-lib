#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

echo "[INFO] rebuilding service routes cache"

if [ -f "glued/Config/routes.yaml" ]; then
  cp -r glued/Config/routes.yaml "${datapath}/$(basename `pwd`)/cache/routes.yaml";
  echo "[PASS] routes rebuilt."
  echo ""
else
  echo "[FAIL] routes.yaml missing in glued/Config."
  echo ""
  exit 1
fi
