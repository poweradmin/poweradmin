#!/bin/sh

set -euo pipefail

DB_PATH="/data/db"
DB_FILE="$DB_PATH/powerdns.db"

if [ ! -e $DB_PATH ]
then
	mkdir -p $DB_PATH
	chmod 777 $DB_PATH
fi

if [ ! -f "$DB_FILE" ]; then
    echo "Creating new SQLite database..."
    sqlite3 "$DB_FILE" < /schema.sqlite3.sql
    chmod 666 "$DB_FILE"
    echo "Database created successfully."
else
    echo "Database already exists. Skipping creation."
fi
