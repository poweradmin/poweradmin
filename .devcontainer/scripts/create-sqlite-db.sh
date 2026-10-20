#!/bin/sh

set -euo pipefail

DATA_PATH="/data"
# One file per instance: SQLite takes a single writer per file, so the SQL and the
# API-backend instances would lock each other out of a shared one.
PDNS_DBS="$DATA_PATH/pdns.db $DATA_PATH/pdns-api.db"

# Ensure data directory exists with proper permissions
if [ ! -e "$DATA_PATH" ]; then
    mkdir -p "$DATA_PATH"
fi
chmod 777 "$DATA_PATH"

# Create PowerDNS databases
for PDNS_DB in $PDNS_DBS; do
    if [ ! -f "$PDNS_DB" ]; then
        echo "Creating SQLite database $PDNS_DB..."
        sqlite3 "$PDNS_DB" < /schema.sqlite3.sql
        chmod 666 "$PDNS_DB"
        echo "Database $PDNS_DB created successfully."
    else
        echo "Database $PDNS_DB already exists. Skipping creation."
    fi
done

echo "SQLite database initialization complete."
