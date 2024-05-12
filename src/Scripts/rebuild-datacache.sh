#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

echo "[INFO] rebuilding service openapi cache"

if [ -f "glued/Config/openapi.yaml" ]; then
  cp -r glued/Config/openapi.yaml "${DATAPATH}/$(basename `pwd`)/cache/openapi.yaml";
  echo "[PASS] openapi cache rebuilt."
  echo ""
else
  echo "[FAIL] openapi.yaml missing in glued/Config."
  echo ""
  exit 1
fi
