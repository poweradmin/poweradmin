#!/usr/bin/env bash

USERNAME="vscode"

set -e

if [ "$(id -u)" -ne 0 ]; then
    echo -e 'Script must be run as root. Use sudo, su, or add "USER root" to your Dockerfile before running this script.'
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

apt-get update && apt-get -y install --no-install-recommends libicu-dev
docker-php-ext-install intl gettext

echo 'Done!'
