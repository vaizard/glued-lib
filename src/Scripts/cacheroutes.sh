#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"

if [ -f "glued/Config/routes.yaml" ]; then
  cp -r glued/Config/routes.yaml "${datapath}/$(basename `pwd`)/cache/routes.yaml";
fi
echo "[PASS] service routes cached"
