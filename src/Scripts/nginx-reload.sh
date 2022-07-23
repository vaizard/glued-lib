#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

systemctl reload nginx
echo "[PASS] MICROSERVICE $(basename `pwd`) running."
echo ""
