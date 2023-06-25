#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

cp -r ./glued/Config/Nginx/* /etc/nginx
if [ ! -f "/etc/nginx/sites-enabled/$(basename `pwd`)" ] && [ -f "/etc/nginx/sites-available/$(basename `pwd`)" ]; then ln -s "/etc/nginx/sites-available/$(basename `pwd`)" /etc/nginx/sites-enabled; fi
