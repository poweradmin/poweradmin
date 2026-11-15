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

echo "Creating admin user with password 'poweradmin123'..."
mysql -u root -p"${MYSQL_ROOT_PASSWORD}" poweradmin <<-EOSQL
    INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES (1, 'admin', '\$2y\$12\$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Administrator', 'admin@example.net', 'Administrator with full rights.', 1, 1, 0);
EOSQL

echo "Poweradmin database initialized successfully."
