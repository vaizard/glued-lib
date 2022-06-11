#!/usr/bin/env bash

if [ -f .env ]; then
  export $(echo $(cat .env | sed 's/#.*//g'| xargs) | envsubst);
fi

if [ -z ${datapath} ]; then
  echo "[FAIL] Datapath ENV variable not set";
  exit 1;
fi
