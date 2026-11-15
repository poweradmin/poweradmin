#!/bin/sh

set -euo pipefail

DATA_PATH="/data"
PDNS_DB="$DATA_PATH/pdns.db"

# Ensure data directory exists with proper permissions
if [ ! -e "$DATA_PATH" ]; then
    mkdir -p "$DATA_PATH"
fi
chmod 777 "$DATA_PATH"

# Create combined PowerDNS + Poweradmin database
if [ ! -f "$PDNS_DB" ]; then
    echo "Creating combined SQLite database..."
    # Create PowerDNS tables first
    sqlite3 "$PDNS_DB" < /schema.sqlite3.sql
    # Add Poweradmin tables
    sqlite3 "$PDNS_DB" < /poweradmin-schema.sql
    # Create admin user with password 'poweradmin123'
    sqlite3 "$PDNS_DB" "INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES (1, 'admin', '\$2y\$12\$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Administrator', 'admin@example.net', 'Administrator with full rights.', 1, 1, 0);"
    chmod 666 "$PDNS_DB"
    echo "Combined database created successfully."
else
    echo "Database already exists. Skipping creation."
fi

echo "SQLite database initialization complete."
