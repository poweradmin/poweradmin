#!/bin/bash
set -e

# Create Keycloak database and user
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE keycloak;
    CREATE USER keycloak WITH ENCRYPTED PASSWORD 'keycloak';
    GRANT ALL PRIVILEGES ON DATABASE keycloak TO keycloak;
    ALTER DATABASE keycloak OWNER TO keycloak;
EOSQL

echo "Keycloak database and user created successfully"