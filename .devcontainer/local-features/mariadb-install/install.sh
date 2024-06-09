#!/usr/bin/env bash

USERNAME="vscode"

set -e

if [ "$(id -u)" -ne 0 ]; then
    echo -e 'Script must be run as root. Use sudo, su, or add "USER root" to your Dockerfile before running this script.'
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

apt-get update && \
    apt-get install -y --no-install-recommends software-properties-common dirmngr && \
    apt-key adv --fetch-keys 'https://mariadb.org/mariadb_release_signing_key.asc' #&& \
    add-apt-repository "deb https://deb.mariadb.org/11.3/debian $(lsb_release -cs) main main" && \
    apt-get update && \
    apt-get install -y mariadb-server

#mysql -u root -e "CREATE DATABASE pdns;"
#mysql -u root -e "CREATE USER 'pdns'@'localhost' IDENTIFIED BY 'pdns';"
#mysql -u root -e "GRANT ALL PRIVILEGES ON pdns.* TO 'pdns'@'localhost';"
#mysql -u root -e "FLUSH PRIVILEGES;"
#mysql -u root -e "EXIT;"
#mysql -u pdns -p pdns < /usr/share/doc/pdns/schema.mysql.sql

echo 'Done!'
