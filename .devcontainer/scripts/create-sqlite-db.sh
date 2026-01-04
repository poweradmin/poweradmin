#!/bin/sh

set -euo pipefail

DATA_PATH="/data"
PDNS_DB="$DATA_PATH/pdns.db"
POWERADMIN_DB="$DATA_PATH/poweradmin.db"

# Ensure data directory exists with proper permissions
if [ ! -e "$DATA_PATH" ]; then
    mkdir -p "$DATA_PATH"
fi
chmod 777 "$DATA_PATH"

# Create PowerDNS database
if [ ! -f "$PDNS_DB" ]; then
    echo "Creating PowerDNS SQLite database..."
    sqlite3 "$PDNS_DB" < /schema.sqlite3.sql
    chmod 666 "$PDNS_DB"
    echo "PowerDNS database created successfully."
else
    echo "PowerDNS database already exists. Skipping creation."
fi

# Create Poweradmin database
if [ ! -f "$POWERADMIN_DB" ]; then
    echo "Creating Poweradmin SQLite database..."
    sqlite3 "$POWERADMIN_DB" < /poweradmin-schema.sql
    chmod 666 "$POWERADMIN_DB"
    echo "Poweradmin database created successfully."
else
    echo "Poweradmin database already exists. Skipping creation."
fi

echo "SQLite database initialization complete."
