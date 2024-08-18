#!/bin/sh

DB_FILE="/data/pdns.db"

if [ ! -f "$DB_FILE" ]; then
    echo "Creating new SQLite database..."
    sqlite3 "$DB_FILE" < /schema.sqlite3.sql
    echo "Database created successfully."
else
    echo "Database already exists. Skipping creation."
fi
