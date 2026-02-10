#!/bin/bash
# =============================================================================
# Import Test Data Script for Poweradmin 4.0.x
# =============================================================================
#
# Purpose: Import comprehensive test data into running Docker databases
#
# This script imports:
# - 5 permission templates with various permission levels
# - 6 test users with different roles (password: Poweradmin123)
# - 4 LDAP users with different roles (password: testpass123)
# - Multiple test domains (master, slave, native, reverse, IDN)
# - Zone ownership records
# - Comprehensive DNS records for UI testing
# - Zone templates for quick zone creation
#
# Usage:
#   ./import-test-data.sh           # Import to all databases
#   ./import-test-data.sh --mysql   # Import to MySQL/MariaDB only
#   ./import-test-data.sh --pgsql   # Import to PostgreSQL only
#   ./import-test-data.sh --sqlite  # Import to SQLite only
#   ./import-test-data.sh --clean   # Clean databases before import
#   ./import-test-data.sh --help    # Show help
#
# =============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEVCONTAINER_DIR="$(dirname "$SCRIPT_DIR")"
SQL_DIR="$DEVCONTAINER_DIR/sql"

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Database credentials (from .devcontainer/.env)
DB_USER="${DB_USER:-pdns}"
DB_PASSWORD="${DB_PASSWORD:-poweradmin}"
DB_NAME="${DB_NAME:-pdns}"

# MySQL uses separate database for Poweradmin tables
MYSQL_DB_NAME="${MYSQL_DB_NAME:-poweradmin}"

MYSQL_CONTAINER="${MYSQL_CONTAINER:-mariadb}"
PGSQL_CONTAINER="${PGSQL_CONTAINER:-postgres}"
SQLITE_CONTAINER="${SQLITE_CONTAINER:-sqlite}"
SQLITE_DB_PATH="${SQLITE_DB_PATH:-/data/pdns.db}"

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}  Poweradmin 4.0.x Test Data Import${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""

# Function to check if container is running
check_container() {
    local container=$1
    if ! docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
        return 1
    fi
    return 0
}

# Function to clean MySQL/MariaDB test data
clean_mysql() {
    echo -e "${YELLOW}Cleaning MySQL/MariaDB test data...${NC}"

    if ! check_container "$MYSQL_CONTAINER"; then
        echo -e "${RED}Container '$MYSQL_CONTAINER' is not running${NC}"
        return 1
    fi

    # Clean Poweradmin tables
    docker exec -i "$MYSQL_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASSWORD" "$MYSQL_DB_NAME" << 'EOSQL'
-- Delete zone templates and their records (except admin's templates)
DELETE ztr FROM zone_templ_records ztr
INNER JOIN zone_templ zt ON ztr.zone_templ_id = zt.id
WHERE zt.owner != (SELECT id FROM users WHERE username = 'admin' LIMIT 1);

DELETE FROM zone_templ WHERE owner != (SELECT id FROM users WHERE username = 'admin' LIMIT 1);

-- Delete zone ownership records
DELETE FROM zones;

-- Delete test users (keep admin)
DELETE FROM users WHERE username != 'admin';

-- Delete permission template items for templates 2-5
DELETE FROM perm_templ_items WHERE templ_id > 1;

-- Delete permission templates (keep Administrator)
DELETE FROM perm_templ WHERE id > 1;
EOSQL

    # Clean PowerDNS tables (in pdns database)
    docker exec -i "$MYSQL_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" << 'EOSQL' 2>/dev/null || true
-- Delete all records and domains
DELETE FROM records;
DELETE FROM domains;
EOSQL

    echo -e "${GREEN}MySQL/MariaDB cleaned${NC}"
}

# Function to clean PostgreSQL test data
clean_pgsql() {
    echo -e "${YELLOW}Cleaning PostgreSQL test data...${NC}"

    if ! check_container "$PGSQL_CONTAINER"; then
        echo -e "${RED}Container '$PGSQL_CONTAINER' is not running${NC}"
        return 1
    fi

    docker exec -i -e PGPASSWORD="$DB_PASSWORD" "$PGSQL_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" << 'EOSQL'
-- Clean all tables safely (check existence before deleting)
DO $$
BEGIN
    -- Delete zone templates and their records
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'zone_templ_records') THEN
        DELETE FROM zone_templ_records WHERE true;
    END IF;
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'zone_templ') THEN
        DELETE FROM zone_templ WHERE true;
    END IF;

    -- Delete zone ownership records
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'zones') THEN
        DELETE FROM zones WHERE true;
    END IF;

    -- Delete test users (keep admin)
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users') THEN
        DELETE FROM users WHERE username != 'admin';
    END IF;

    -- Delete permission template items for templates 2-5
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'perm_templ_items') THEN
        DELETE FROM perm_templ_items WHERE templ_id > 1;
    END IF;

    -- Delete permission templates (keep Administrator)
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'perm_templ') THEN
        DELETE FROM perm_templ WHERE id > 1;
    END IF;

    -- Delete all records and domains
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'records') THEN
        DELETE FROM records;
    END IF;
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'domains') THEN
        DELETE FROM domains;
    END IF;
END $$;

-- Reset sequences safely
DO $$
BEGIN
    IF EXISTS (SELECT FROM pg_class WHERE relname = 'perm_templ_id_seq') THEN
        PERFORM setval('perm_templ_id_seq', 1);
    END IF;
    IF EXISTS (SELECT FROM pg_class WHERE relname = 'users_id_seq') THEN
        IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users') THEN
            PERFORM setval('users_id_seq', COALESCE((SELECT MAX(id) FROM users), 1));
        ELSE
            PERFORM setval('users_id_seq', 1);
        END IF;
    END IF;
    IF EXISTS (SELECT FROM pg_class WHERE relname = 'domains_id_seq') THEN
        PERFORM setval('domains_id_seq', 1);
    END IF;
    IF EXISTS (SELECT FROM pg_class WHERE relname = 'records_id_seq') THEN
        PERFORM setval('records_id_seq', 1);
    END IF;
    IF EXISTS (SELECT FROM pg_class WHERE relname = 'zones_id_seq') THEN
        PERFORM setval('zones_id_seq', 1);
    END IF;
    IF EXISTS (SELECT FROM pg_class WHERE relname = 'zone_templ_id_seq') THEN
        PERFORM setval('zone_templ_id_seq', 1);
    END IF;
END $$;
EOSQL

    echo -e "${GREEN}PostgreSQL cleaned${NC}"
}

# Function to clean SQLite test data
clean_sqlite() {
    echo -e "${YELLOW}Cleaning SQLite test data...${NC}"

    if ! check_container "$SQLITE_CONTAINER"; then
        echo -e "${RED}Container '$SQLITE_CONTAINER' is not running${NC}"
        return 1
    fi

    # Clean Poweradmin tables (ignore errors if PowerDNS tables don't exist)
    docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" << 'EOSQL' 2>/dev/null || true
-- Delete zone templates and their records
DELETE FROM zone_templ_records WHERE 1=1;
DELETE FROM zone_templ WHERE 1=1;

-- Delete zone ownership records
DELETE FROM zones WHERE 1=1;

-- Delete test users (keep admin)
DELETE FROM users WHERE username != 'admin';

-- Delete permission template items for templates 2-5
DELETE FROM perm_templ_items WHERE templ_id > 1;

-- Delete permission templates (keep Administrator)
DELETE FROM perm_templ WHERE id > 1;

-- Delete all records and domains (if tables exist)
DELETE FROM records WHERE 1=1;
DELETE FROM domains WHERE 1=1;
EOSQL

    echo -e "${GREEN}SQLite cleaned${NC}"
}

# Function to import MySQL/MariaDB data
import_mysql() {
    echo -e "${YELLOW}Importing to MySQL/MariaDB...${NC}"

    if ! check_container "$MYSQL_CONTAINER"; then
        echo -e "${RED}Container '$MYSQL_CONTAINER' is not running${NC}"
        return 1
    fi

    if [ ! -f "$SQL_DIR/test-users-permissions-mysql.sql" ]; then
        echo -e "${RED}MySQL SQL file not found: $SQL_DIR/test-users-permissions-mysql.sql${NC}"
        return 1
    fi

    # Check if PowerDNS schema exists in pdns database, import if needed
    local has_domains_table=$(docker exec "$MYSQL_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASSWORD" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME' AND table_name = 'domains';" 2>/dev/null || echo "0")

    if [ "$has_domains_table" = "0" ]; then
        echo -e "${YELLOW}PowerDNS schema not found in '$DB_NAME' database, importing...${NC}"
        local pdns_schema="$SCRIPT_DIR/pdns/modules/gmysqlbackend/schema.mysql.sql"
        if [ -f "$pdns_schema" ]; then
            if docker exec -i "$MYSQL_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$pdns_schema" 2>/dev/null; then
                echo -e "${GREEN}PowerDNS schema imported${NC}"
            else
                echo -e "${RED}PowerDNS schema import failed${NC}"
                return 1
            fi
        else
            echo -e "${RED}PowerDNS schema file not found at $pdns_schema${NC}"
            return 1
        fi
    fi

    # Check if Poweradmin schema exists in poweradmin database, import if needed
    local has_users_table=$(docker exec "$MYSQL_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASSWORD" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$MYSQL_DB_NAME' AND table_name = 'users';" 2>/dev/null || echo "0")

    if [ "$has_users_table" = "0" ]; then
        echo -e "${YELLOW}Poweradmin schema not found in '$MYSQL_DB_NAME' database, importing...${NC}"
        local poweradmin_schema="$SCRIPT_DIR/../../sql/poweradmin-mysql-db-structure.sql"
        if [ -f "$poweradmin_schema" ]; then
            if docker exec -i "$MYSQL_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASSWORD" "$MYSQL_DB_NAME" < "$poweradmin_schema" 2>/dev/null; then
                echo -e "${GREEN}Poweradmin schema imported${NC}"
            else
                echo -e "${RED}Poweradmin schema import failed${NC}"
                return 1
            fi
        else
            echo -e "${RED}Poweradmin schema file not found at $poweradmin_schema${NC}"
            return 1
        fi
    fi

    # Import users, permissions, and zones
    if docker exec -i "$MYSQL_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASSWORD" "$MYSQL_DB_NAME" < "$SQL_DIR/test-users-permissions-mysql.sql" 2>/dev/null; then
        echo -e "${GREEN}MySQL/MariaDB users and zones imported${NC}"

        # Import comprehensive DNS records
        if [ -f "$SQL_DIR/test-dns-records-mysql.sql" ]; then
            echo -e "${YELLOW}Importing comprehensive DNS records...${NC}"
            if docker exec -i "$MYSQL_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASSWORD" "$MYSQL_DB_NAME" < "$SQL_DIR/test-dns-records-mysql.sql" 2>/dev/null; then
                echo -e "${GREEN}MySQL/MariaDB DNS records imported${NC}"
            else
                echo -e "${YELLOW}DNS records import had issues (may already exist)${NC}"
            fi
        fi

        return 0
    else
        echo -e "${RED}MySQL/MariaDB import failed${NC}"
        return 1
    fi
}

# Function to import PostgreSQL data
import_pgsql() {
    echo -e "${YELLOW}Importing to PostgreSQL...${NC}"

    if ! check_container "$PGSQL_CONTAINER"; then
        echo -e "${RED}Container '$PGSQL_CONTAINER' is not running${NC}"
        return 1
    fi

    if [ ! -f "$SQL_DIR/test-users-permissions-pgsql.sql" ]; then
        echo -e "${RED}PostgreSQL SQL file not found: $SQL_DIR/test-users-permissions-pgsql.sql${NC}"
        return 1
    fi

    # Check if PowerDNS schema exists, import if needed
    local has_domains_table=$(docker exec -e PGPASSWORD="$DB_PASSWORD" "$PGSQL_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -tAc "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'domains');" 2>/dev/null || echo "f")

    if [ "$has_domains_table" != "t" ]; then
        echo -e "${YELLOW}PowerDNS schema not found, importing...${NC}"
        local pdns_schema="$SCRIPT_DIR/pdns/modules/gpgsqlbackend/schema.pgsql.sql"
        if [ -f "$pdns_schema" ]; then
            if docker exec -i -e PGPASSWORD="$DB_PASSWORD" "$PGSQL_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" < "$pdns_schema" > /dev/null 2>&1; then
                echo -e "${GREEN}PowerDNS schema imported${NC}"
            else
                echo -e "${RED}PowerDNS schema import failed${NC}"
                return 1
            fi
        else
            echo -e "${RED}PowerDNS schema file not found at $pdns_schema${NC}"
            return 1
        fi
    fi

    # Check if Poweradmin schema exists, import if needed
    local has_users_table=$(docker exec -e PGPASSWORD="$DB_PASSWORD" "$PGSQL_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -tAc "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users');" 2>/dev/null || echo "f")

    if [ "$has_users_table" != "t" ]; then
        echo -e "${YELLOW}Poweradmin schema not found, importing...${NC}"
        local poweradmin_schema="$SCRIPT_DIR/../../sql/poweradmin-pgsql-db-structure.sql"
        if [ -f "$poweradmin_schema" ]; then
            if docker exec -i -e PGPASSWORD="$DB_PASSWORD" "$PGSQL_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" < "$poweradmin_schema" > /dev/null 2>&1; then
                echo -e "${GREEN}Poweradmin schema imported${NC}"
            else
                echo -e "${RED}Poweradmin schema import failed${NC}"
                return 1
            fi
        else
            echo -e "${RED}Poweradmin schema file not found at $poweradmin_schema${NC}"
            return 1
        fi
    fi

    # Import users, permissions, and zones
    if docker exec -i -e PGPASSWORD="$DB_PASSWORD" "$PGSQL_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" < "$SQL_DIR/test-users-permissions-pgsql.sql" > /dev/null 2>&1; then
        echo -e "${GREEN}PostgreSQL users and zones imported${NC}"

        # Import comprehensive DNS records
        if [ -f "$SQL_DIR/test-dns-records-pgsql.sql" ]; then
            echo -e "${YELLOW}Importing comprehensive DNS records...${NC}"
            if docker exec -i -e PGPASSWORD="$DB_PASSWORD" "$PGSQL_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" < "$SQL_DIR/test-dns-records-pgsql.sql" > /dev/null 2>&1; then
                echo -e "${GREEN}PostgreSQL DNS records imported${NC}"
            else
                echo -e "${YELLOW}DNS records import had issues (may already exist)${NC}"
            fi
        fi

        return 0
    else
        echo -e "${RED}PostgreSQL import failed${NC}"
        return 1
    fi
}

# Function to import SQLite data
import_sqlite() {
    echo -e "${YELLOW}Importing to SQLite...${NC}"

    if ! check_container "$SQLITE_CONTAINER"; then
        echo -e "${RED}Container '$SQLITE_CONTAINER' is not running${NC}"
        return 1
    fi

    if [ ! -f "$SQL_DIR/test-users-permissions-sqlite.sql" ]; then
        echo -e "${RED}SQLite SQL file not found: $SQL_DIR/test-users-permissions-sqlite.sql${NC}"
        return 1
    fi

    # Check if PowerDNS schema exists, import if needed
    local has_domains_table=$(docker exec "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" "SELECT name FROM sqlite_master WHERE type='table' AND name='domains';" 2>/dev/null || echo "")

    if [ -z "$has_domains_table" ]; then
        echo -e "${YELLOW}PowerDNS schema not found, importing...${NC}"
        local pdns_schema="$SCRIPT_DIR/pdns/modules/gsqlite3backend/schema.sqlite3.sql"
        if [ -f "$pdns_schema" ]; then
            if docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$pdns_schema" > /dev/null 2>&1; then
                echo -e "${GREEN}PowerDNS schema imported${NC}"
            else
                echo -e "${YELLOW}PowerDNS schema import had issues (may already exist)${NC}"
            fi
        else
            echo -e "${YELLOW}PowerDNS schema file not found at $pdns_schema${NC}"
        fi
    fi

    # Check if Poweradmin schema exists, import if needed
    local has_users_table=$(docker exec "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" "SELECT name FROM sqlite_master WHERE type='table' AND name='users';" 2>/dev/null || echo "")

    if [ -z "$has_users_table" ]; then
        echo -e "${YELLOW}Poweradmin schema not found, importing...${NC}"
        local poweradmin_schema="$SCRIPT_DIR/../../sql/poweradmin-sqlite-db-structure.sql"
        if [ -f "$poweradmin_schema" ]; then
            if docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$poweradmin_schema" > /dev/null 2>&1; then
                echo -e "${GREEN}Poweradmin schema imported${NC}"
            else
                echo -e "${RED}Poweradmin schema import failed${NC}"
                return 1
            fi
        else
            echo -e "${RED}Poweradmin schema file not found at $poweradmin_schema${NC}"
            return 1
        fi
    fi

    # Import users, permissions, and zones
    if docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$SQL_DIR/test-users-permissions-sqlite.sql" > /dev/null 2>&1; then
        echo -e "${GREEN}SQLite users and zones imported${NC}"

        # Import comprehensive DNS records
        if [ -f "$SQL_DIR/test-dns-records-sqlite.sql" ]; then
            echo -e "${YELLOW}Importing comprehensive DNS records...${NC}"
            if docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$SQL_DIR/test-dns-records-sqlite.sql" > /dev/null 2>&1; then
                echo -e "${GREEN}SQLite DNS records imported${NC}"
            else
                echo -e "${YELLOW}DNS records import had issues (may already exist)${NC}"
            fi
        fi

        return 0
    else
        echo -e "${RED}SQLite import failed${NC}"
        echo -e "${YELLOW}Note: Ensure database exists at $SQLITE_DB_PATH in the container${NC}"
        return 1
    fi
}

# Function to import LDAP test users
import_ldap() {
    echo -e "${YELLOW}Setting up LDAP test users...${NC}"

    local LDAP_CONTAINER="${LDAP_CONTAINER:-ldap}"
    local LDAP_ADMIN_DN="cn=admin,dc=poweradmin,dc=org"
    local LDAP_ADMIN_PW="poweradmin"
    local LDAP_BASE_DN="dc=poweradmin,dc=org"

    if ! check_container "$LDAP_CONTAINER"; then
        echo -e "${YELLOW}LDAP container '$LDAP_CONTAINER' is not running - skipping LDAP setup${NC}"
        return 0
    fi

    # Wait for LDAP to be ready (max 30 seconds)
    local timeout=30
    local counter=0
    until docker exec "$LDAP_CONTAINER" ldapsearch -x -H ldap://localhost -b "$LDAP_BASE_DN" -D "$LDAP_ADMIN_DN" -w "$LDAP_ADMIN_PW" >/dev/null 2>&1; do
        sleep 1
        counter=$((counter + 1))
        if [ $counter -ge $timeout ]; then
            echo -e "${YELLOW}LDAP service not ready - skipping LDAP setup${NC}"
            return 0
        fi
    done

    # Check if users already exist
    if docker exec "$LDAP_CONTAINER" ldapsearch -x -H ldap://localhost -b "ou=users,$LDAP_BASE_DN" -D "$LDAP_ADMIN_DN" -w "$LDAP_ADMIN_PW" "(uid=ldap-admin)" 2>/dev/null | grep -q "uid=ldap-admin"; then
        echo -e "${GREEN}LDAP test users already exist${NC}"
        return 0
    fi

    # Add LDAP test users
    if docker exec "$LDAP_CONTAINER" ldapadd -x -D "$LDAP_ADMIN_DN" -w "$LDAP_ADMIN_PW" -f /ldap-test-users.ldif 2>/dev/null; then
        echo -e "${GREEN}LDAP test users created${NC}"
        return 0
    else
        echo -e "${YELLOW}LDAP users may already exist or failed to create${NC}"
        return 0
    fi
}

# Function to show summary
show_summary() {
    echo ""
    echo -e "${BLUE}================================================${NC}"
    echo -e "${BLUE}  Test Data Summary${NC}"
    echo -e "${BLUE}================================================${NC}"
    echo ""
    echo -e "${GREEN}Local Test Users (password: Poweradmin123):${NC}"
    echo "  Username  | Password       | Template        | Active"
    echo "  ----------|----------------|-----------------|-------"
    echo "  admin     | Poweradmin123  | Administrator   | Yes"
    echo "  manager   | Poweradmin123  | Zone Manager    | Yes"
    echo "  client    | Poweradmin123  | Client Editor   | Yes"
    echo "  viewer    | Poweradmin123  | Read Only       | Yes"
    echo "  noperm    | Poweradmin123  | No Access       | Yes"
    echo "  inactive  | Poweradmin123  | No Access       | No"
    echo ""
    echo -e "${GREEN}LDAP Test Users (password: testpass123):${NC}"
    echo "  Username     | Password     | Template        | Active"
    echo "  -------------|--------------|-----------------|-------"
    echo "  ldap-admin   | testpass123  | Administrator   | Yes"
    echo "  ldap-manager | testpass123  | Zone Manager    | Yes"
    echo "  ldap-client  | testpass123  | Client Editor   | Yes"
    echo "  ldap-viewer  | testpass123  | Read Only       | Yes"
    echo ""
    echo -e "${GREEN}Test Domains Created:${NC}"
    echo "  Type   | Domain                                          | Owner(s)"
    echo "  -------|------------------------------------------------|------------------"
    echo "  MASTER | admin-zone.example.com                          | admin"
    echo "  MASTER | manager-zone.example.com                        | manager"
    echo "  MASTER | client-zone.example.com                         | client"
    echo "  MASTER | shared-zone.example.com                         | manager, client"
    echo "  MASTER | viewer-zone.example.com                         | viewer"
    echo "  NATIVE | native-zone.example.org                         | manager"
    echo "  NATIVE | secondary-native.example.org                    | manager"
    echo "  SLAVE  | slave-zone.example.net                          | admin"
    echo "  SLAVE  | external-slave.example.net                      | admin"
    echo "  MASTER | 2.0.192.in-addr.arpa (reverse IPv4)             | admin"
    echo "  MASTER | 168.192.in-addr.arpa (reverse IPv4)             | admin"
    echo "  MASTER | 8.b.d.0.1.0.0.2.ip6.arpa (reverse IPv6)         | admin"
    echo "  MASTER | xn--verstt-eua3l.info (IDN)                     | manager"
    echo "  MASTER | very-long-subdomain-name-for-testing...         | client"
    echo "  MASTER | another.very.deeply.nested.subdomain...         | client"
    echo ""
    echo -e "${GREEN}Permission Templates:${NC}"
    echo "  ID | Name           | Permissions"
    echo "  ---|----------------|------------------------------------------"
    echo "   1 | Administrator  | user_is_ueberuser (full access)"
    echo "   2 | Zone Manager   | zone_master_add, zone_slave_add, zone_content_*"
    echo "   3 | Client Editor  | zone_content_view_own, zone_content_edit_own_as_client"
    echo "   4 | Read Only      | zone_content_view_own, search"
    echo "   5 | No Access      | (none)"
    echo ""
    echo -e "${GREEN}Zone Templates:${NC}"
    echo "  Name                     | Owner   | Description"
    echo "  -------------------------|---------|----------------------------------"
    echo "  Basic Web Zone           | admin   | www, mail, MX records"
    echo "  Full Zone Template       | admin   | A, MX, CNAME, SPF, DMARC records"
    echo "  Manager Custom Template  | manager | Custom template for manager user"
    echo ""
    echo -e "${GREEN}Access URLs:${NC}"
    echo "  MySQL:    http://localhost:8080 (nginx)"
    echo "  PostgreSQL: http://localhost:8081 (apache)"
    echo "  SQLite:   http://localhost:8082 (caddy)"
    echo "  Adminer:  http://localhost:8090"
    echo "  LDAP Admin: https://localhost:8443"
    echo ""
}

# Main import logic
main() {
    local import_mysql=false
    local import_pgsql=false
    local import_sqlite=false
    local import_all=true
    local do_clean=false

    # Parse command line arguments
    while [[ $# -gt 0 ]]; do
        case $1 in
            --mysql)
                import_mysql=true
                import_all=false
                shift
                ;;
            --pgsql|--postgres)
                import_pgsql=true
                import_all=false
                shift
                ;;
            --sqlite)
                import_sqlite=true
                import_all=false
                shift
                ;;
            --clean)
                do_clean=true
                shift
                ;;
            --help|-h)
                echo "Usage: $0 [OPTIONS]"
                echo ""
                echo "Import test data into Poweradmin databases"
                echo ""
                echo "Options:"
                echo "  --mysql       Import to MySQL/MariaDB only"
                echo "  --pgsql       Import to PostgreSQL only"
                echo "  --sqlite      Import to SQLite only"
                echo "  --clean       Clean databases before import"
                echo "  --help        Show this help message"
                echo ""
                echo "Environment variables:"
                echo "  DB_USER           Database username (default: pdns)"
                echo "  DB_PASSWORD       Database password (default: poweradmin)"
                echo "  DB_NAME           Database name (default: pdns)"
                echo "  MYSQL_CONTAINER   MySQL container name (default: mariadb)"
                echo "  PGSQL_CONTAINER   PostgreSQL container name (default: postgres)"
                echo "  SQLITE_CONTAINER  SQLite container name (default: sqlite)"
                echo ""
                exit 0
                ;;
            *)
                echo -e "${RED}Unknown option: $1${NC}"
                echo "Use --help for usage information"
                exit 1
                ;;
        esac
    done

    # Determine which databases to import
    if [ "$import_all" = true ]; then
        import_mysql=true
        import_pgsql=true
        import_sqlite=true
    fi

    local success_count=0
    local fail_count=0

    # Clean if requested
    if [ "$do_clean" = true ]; then
        echo -e "${YELLOW}Cleaning databases before import...${NC}"
        echo ""

        if [ "$import_mysql" = true ]; then
            if clean_mysql; then
                ((success_count++))
            else
                ((fail_count++))
            fi
        fi

        if [ "$import_pgsql" = true ]; then
            if clean_pgsql; then
                ((success_count++))
            else
                ((fail_count++))
            fi
        fi

        if [ "$import_sqlite" = true ]; then
            if clean_sqlite; then
                ((success_count++))
            else
                ((fail_count++))
            fi
        fi

        echo ""
    fi

    # Import data
    if [ "$import_mysql" = true ]; then
        if import_mysql; then
            ((success_count++))
        else
            ((fail_count++))
        fi
    fi

    if [ "$import_pgsql" = true ]; then
        if import_pgsql; then
            ((success_count++))
        else
            ((fail_count++))
        fi
    fi

    if [ "$import_sqlite" = true ]; then
        if import_sqlite; then
            ((success_count++))
        else
            ((fail_count++))
        fi
    fi

    # Import LDAP test users (always try if any database import was done)
    if [ "$import_mysql" = true ] || [ "$import_pgsql" = true ] || [ "$import_sqlite" = true ]; then
        import_ldap
    fi

    # Show summary
    show_summary

    # Final status
    echo ""
    if [ $fail_count -eq 0 ]; then
        echo -e "${GREEN}Import completed successfully!${NC}"
        exit 0
    else
        echo -e "${YELLOW}Import completed with some failures${NC}"
        echo -e "   Success: $success_count, Failed: $fail_count"
        exit 1
    fi
}

main "$@"
