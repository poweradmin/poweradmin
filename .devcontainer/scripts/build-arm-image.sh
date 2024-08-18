#!/usr/bin/env bash

echo "- Reading .env file"
source ../.env

cd pdns 2>/dev/null || {
  echo "- Downloading PowerDNS source code"
  git clone https://github.com/PowerDNS/pdns.git pdns
  cd pdns
}

echo "- Updating PowerDNS source code"
git fetch --all --tags
git checkout -- .
git checkout auth-${PDNS_VERSION}
git submodule update --init
git apply ../add-liblua.patch

echo "- Building PowerDNS image"
docker buildx build -f Dockerfile-auth --platform linux/arm64 -t powerdns/pdns-auth-${PDNS_BRANCH}:${PDNS_VERSION} .
