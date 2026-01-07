#!/bin/sh

set -euo pipefail

DATA_PATH="/data"
PDNS_DB="$DATA_PATH/pdns.db"

# Ensure data directory exists with proper permissions
if [ ! -e "$DATA_PATH" ]; then
    mkdir -p "$DATA_PATH"
fi
chmod 777 "$DATA_PATH"

# Create PowerDNS database
if [ ! -f "$PDNS_DB" ]; then
    echo "Creating SQLite database..."
    sqlite3 "$PDNS_DB" < /schema.sqlite3.sql
    chmod 666 "$PDNS_DB"
    echo "Database created successfully."
else
    echo "Database already exists. Skipping creation."
fi

echo "SQLite database initialization complete."
