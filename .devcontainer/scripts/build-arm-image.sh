#!/usr/bin/env bash

set -euo pipefail

log() {
  echo "[$(date +'%Y-%m-%d %H:%M:%S')]: $*"
}

check_env_var() {
  if [ -z "${!1:-}" ]; then
    log "Error: Environment variable $1 is not set."
    exit 1
  fi
}

check_not_symlink() {
  if [ -L "$1" ]; then
    log "Error: $1 is a symlink."
    exit 1
  fi
}

log "Reading .env file"
source ../.env

# Check required environment variables
check_env_var "PDNS_VERSION"
check_env_var "PDNS_BRANCH"

if [ -d "pdns" ]; then
  check_not_symlink "pdns"
  log "Removing existing PowerDNS source code"
  rm -rf pdns
fi

log "Downloading PowerDNS source code"
if ! git clone -b auth-${PDNS_VERSION} --depth 1 https://github.com/PowerDNS/pdns.git pdns; then
  log "Error: Failed to clone PowerDNS repository"
  exit 1
fi

cd pdns

log "Updating PowerDNS source code"
if ! git submodule update --init; then
  log "Error: Failed to update submodules"
  exit 1
fi

if ! git apply ../add-liblua.patch; then
  log "Error: Failed to apply patch"
  exit 1
fi

log "Building PowerDNS image"
if ! docker buildx build -f Dockerfile-auth --platform linux/arm64 -t powerdns/pdns-auth-${PDNS_BRANCH}:${PDNS_VERSION} .; then
  log "Error: Failed to build Docker image"
  exit 1
fi

log "PowerDNS image built successfully"
