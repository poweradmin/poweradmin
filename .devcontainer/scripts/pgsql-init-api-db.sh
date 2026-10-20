#!/bin/bash
# Create the API-backend instance's own database with the PowerDNS schema, so it
# never writes to the rows the SQL instance is using.
set -e

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -c "CREATE DATABASE pdns_api OWNER \"$POSTGRES_USER\";"
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d pdns_api \
    -f /docker-entrypoint-initdb.d/01-init.sql
