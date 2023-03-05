#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
source "$DIR/loadenv.sh"
source "$DIR/loadenv.sh"

[[ $(lsb_release -is) != 'Ubuntu' ]] && echo "[WARN] Unsupported OS/Distro. Please ensure that PHP-FPM is reconfigured correctly."
cp -r ./glued/Config/Php/* /etc/php/$(php -v | awk '/^PHP/ {print $2}' | cut -f1,2 -d.)/fpm/conf.d

