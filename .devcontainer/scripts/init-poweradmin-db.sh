#!/bin/bash
# Initialize Poweradmin database with separate schema
# This script runs during MySQL container initialization

set -e

echo "Creating poweradmin database..."
mysql -u root -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS poweradmin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    GRANT ALL PRIVILEGES ON poweradmin.* TO '${MYSQL_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL

echo "Importing poweradmin schema..."
mysql -u root -p"${MYSQL_ROOT_PASSWORD}" poweradmin < /docker-entrypoint-initdb.d/poweradmin-schema.sql

echo "Setting admin password to 'poweradmin123'..."
mysql -u root -p"${MYSQL_ROOT_PASSWORD}" poweradmin <<-EOSQL
    UPDATE users SET password = '\$2y\$12\$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi' WHERE username = 'admin';
EOSQL

echo "Poweradmin database initialized successfully."
