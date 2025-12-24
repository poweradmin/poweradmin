#!/bin/bash
# =============================================================================
# Import Test Data Script for Poweradmin 3.x
# =============================================================================
#
# Purpose: Import comprehensive test data into running Docker databases
#
# This script imports:
# - 5 permission templates with various permission levels
# - 6 test users with different roles (password: poweradmin123)
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
MYSQL_USER="${MYSQL_USER:-pdns}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-poweradmin}"
MYSQL_DATABASE="${MYSQL_DATABASE:-poweradmin}"
MYSQL_PDNS_DATABASE="${MYSQL_PDNS_DATABASE:-pdns}"
MYSQL_CONTAINER="${MYSQL_CONTAINER:-mariadb}"

PGSQL_USER="${PGSQL_USER:-pdns}"
PGSQL_PASSWORD="${PGSQL_PASSWORD:-poweradmin}"
PGSQL_DATABASE="${PGSQL_DATABASE:-pdns}"
PGSQL_CONTAINER="${PGSQL_CONTAINER:-postgres}"

SQLITE_CONTAINER="${SQLITE_CONTAINER:-sqlite}"
SQLITE_DB_PATH="${SQLITE_DB_PATH:-/data/poweradmin.db}"
SQLITE_PDNS_DB_PATH="${SQLITE_PDNS_DB_PATH:-/data/db/powerdns.db}"

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}  Poweradmin 3.x Test Data Import${NC}"
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
    echo -e "${YELLOW}🧹 Cleaning MySQL/MariaDB test data...${NC}"

    if ! check_container "$MYSQL_CONTAINER"; then
        echo -e "${RED}❌ Container '$MYSQL_CONTAINER' is not running${NC}"
        return 1
    fi

    # Clean test data (keep admin user and Administrator template)
    # Note: 3.x uses unified pdns database for both PowerDNS and Poweradmin tables
    docker exec -i "$MYSQL_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" pdns << 'EOSQL'
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

-- Delete all records
DELETE FROM records;

-- Delete all domains
DELETE FROM domains;
EOSQL

    echo -e "${GREEN}✅ MySQL/MariaDB cleaned${NC}"
}

# Function to clean PostgreSQL test data
clean_pgsql() {
    echo -e "${YELLOW}🧹 Cleaning PostgreSQL test data...${NC}"

    if ! check_container "$PGSQL_CONTAINER"; then
        echo -e "${RED}❌ Container '$PGSQL_CONTAINER' is not running${NC}"
        return 1
    fi

    docker exec -i -e PGPASSWORD="$PGSQL_PASSWORD" "$PGSQL_CONTAINER" psql -U "$PGSQL_USER" -d "$PGSQL_DATABASE" << 'EOSQL'
-- Delete zone templates and their records
DELETE FROM zone_templ_records;
DELETE FROM zone_templ;

-- Delete zone ownership records
DELETE FROM zones;

-- Delete test users (keep admin)
DELETE FROM users WHERE username != 'admin';

-- Delete permission template items for templates 2-5
DELETE FROM perm_templ_items WHERE templ_id > 1;

-- Delete permission templates (keep Administrator)
DELETE FROM perm_templ WHERE id > 1;

-- Delete all records
DELETE FROM records;

-- Delete all domains
DELETE FROM domains;

-- Reset sequences
SELECT setval('perm_templ_id_seq', 1);
SELECT setval('users_id_seq', (SELECT COALESCE(MAX(id), 1) FROM users));
SELECT setval('domains_id_seq', 1);
SELECT setval('records_id_seq', 1);
SELECT setval('zones_id_seq', 1);
SELECT setval('zone_templ_id_seq', 1);
EOSQL

    echo -e "${GREEN}✅ PostgreSQL cleaned${NC}"
}

# Function to import MySQL/MariaDB data
import_mysql() {
    echo -e "${YELLOW}📦 Importing to MySQL/MariaDB...${NC}"

    if ! check_container "$MYSQL_CONTAINER"; then
        echo -e "${RED}❌ Container '$MYSQL_CONTAINER' is not running${NC}"
        return 1
    fi

    if [ ! -f "$SQL_DIR/test-users-permissions-mysql-combined.sql" ]; then
        echo -e "${RED}❌ MySQL SQL file not found: $SQL_DIR/test-users-permissions-mysql-combined.sql${NC}"
        return 1
    fi

    # Import users, permissions, and zones
    # Note: The SQL file uses USE pdns; so we don't need to specify database here
    local output
    local exit_code
    output=$(docker exec -i "$MYSQL_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" pdns < "$SQL_DIR/test-users-permissions-mysql-combined.sql" 2>&1)
    exit_code=$?

    if [ $exit_code -eq 0 ]; then
        echo -e "${GREEN}✅ MySQL/MariaDB users and zones imported${NC}"

        # Import comprehensive DNS records
        if [ -f "$SQL_DIR/test-dns-records-mysql.sql" ]; then
            echo -e "${YELLOW}📦 Importing comprehensive DNS records...${NC}"
            output=$(docker exec -i "$MYSQL_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" pdns < "$SQL_DIR/test-dns-records-mysql.sql" 2>&1)
            exit_code=$?

            if [ $exit_code -eq 0 ]; then
                echo -e "${GREEN}✅ MySQL/MariaDB DNS records imported${NC}"
            else
                echo -e "${YELLOW}⚠️  DNS records import had issues (may already exist)${NC}"
            fi
        fi

        return 0
    else
        echo -e "${RED}❌ MySQL/MariaDB import failed${NC}"
        echo "$output" | grep -v "Using a password" >&2
        return 1
    fi
}

# Function to import PostgreSQL data
import_pgsql() {
    echo -e "${YELLOW}📦 Importing to PostgreSQL...${NC}"

    if ! check_container "$PGSQL_CONTAINER"; then
        echo -e "${RED}❌ Container '$PGSQL_CONTAINER' is not running${NC}"
        return 1
    fi

    if [ ! -f "$SQL_DIR/test-users-permissions-pgsql.sql" ]; then
        echo -e "${RED}❌ PostgreSQL SQL file not found: $SQL_DIR/test-users-permissions-pgsql.sql${NC}"
        return 1
    fi

    # Import users, permissions, and zones
    if docker exec -i -e PGPASSWORD="$PGSQL_PASSWORD" "$PGSQL_CONTAINER" psql -U "$PGSQL_USER" -d "$PGSQL_DATABASE" < "$SQL_DIR/test-users-permissions-pgsql.sql" > /dev/null 2>&1; then
        echo -e "${GREEN}✅ PostgreSQL users and zones imported${NC}"

        # Import comprehensive DNS records
        if [ -f "$SQL_DIR/test-dns-records-pgsql.sql" ]; then
            echo -e "${YELLOW}📦 Importing comprehensive DNS records...${NC}"
            if docker exec -i -e PGPASSWORD="$PGSQL_PASSWORD" "$PGSQL_CONTAINER" psql -U "$PGSQL_USER" -d "$PGSQL_DATABASE" < "$SQL_DIR/test-dns-records-pgsql.sql" > /dev/null 2>&1; then
                echo -e "${GREEN}✅ PostgreSQL DNS records imported${NC}"
            else
                echo -e "${YELLOW}⚠️  DNS records import had issues (may already exist)${NC}"
            fi
        fi

        return 0
    else
        echo -e "${RED}❌ PostgreSQL import failed${NC}"
        return 1
    fi
}

# Function to import SQLite data
import_sqlite() {
    echo -e "${YELLOW}📦 Importing to SQLite...${NC}"

    if ! check_container "$SQLITE_CONTAINER"; then
        echo -e "${RED}❌ Container '$SQLITE_CONTAINER' is not running${NC}"
        return 1
    fi

    if [ ! -f "$SQL_DIR/test-users-permissions-sqlite.sql" ]; then
        echo -e "${RED}❌ SQLite SQL file not found: $SQL_DIR/test-users-permissions-sqlite.sql${NC}"
        return 1
    fi

    # Import users, permissions, and zones
    if docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$SQL_DIR/test-users-permissions-sqlite.sql" > /dev/null 2>&1; then
        echo -e "${GREEN}✅ SQLite users and zones imported${NC}"

        # Import comprehensive DNS records
        if [ -f "$SQL_DIR/test-dns-records-sqlite.sql" ]; then
            echo -e "${YELLOW}📦 Importing comprehensive DNS records...${NC}"
            if docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$SQL_DIR/test-dns-records-sqlite.sql" > /dev/null 2>&1; then
                echo -e "${GREEN}✅ SQLite DNS records imported${NC}"
            else
                echo -e "${YELLOW}⚠️  DNS records import had issues (may already exist)${NC}"
            fi
        fi

        return 0
    else
        echo -e "${RED}❌ SQLite import failed${NC}"
        echo -e "${YELLOW}Note: Ensure database exists at $SQLITE_DB_PATH in the container${NC}"
        return 1
    fi
}

# Function to show summary
show_summary() {
    echo ""
    echo -e "${BLUE}================================================${NC}"
    echo -e "${BLUE}  Test Data Summary${NC}"
    echo -e "${BLUE}================================================${NC}"
    echo ""
    echo -e "${GREEN}Test Users Created:${NC}"
    echo "  Username  | Password       | Template        | Active"
    echo "  ----------|----------------|-----------------|-------"
    echo "  admin     | poweradmin123  | Administrator   | Yes"
    echo "  manager   | poweradmin123  | Zone Manager    | Yes"
    echo "  client    | poweradmin123  | Client Editor   | Yes"
    echo "  viewer    | poweradmin123  | Read Only       | Yes"
    echo "  noperm    | poweradmin123  | No Access       | Yes"
    echo "  inactive  | poweradmin123  | No Access       | No"
    echo ""
    echo -e "${GREEN}Test Domains Created:${NC}"
    echo "  Type   | Domain                              | Owner(s)"
    echo "  -------|-------------------------------------|------------------"
    echo "  MASTER | admin-zone.example.com              | admin"
    echo "  MASTER | manager-zone.example.com            | manager"
    echo "  MASTER | client-zone.example.com             | client"
    echo "  MASTER | shared-zone.example.com             | manager, client"
    echo "  MASTER | viewer-zone.example.com             | viewer"
    echo "  NATIVE | native-zone.example.org             | manager"
    echo "  SLAVE  | slave-zone.example.net              | admin"
    echo "  MASTER | 2.0.192.in-addr.arpa (reverse)      | admin"
    echo "  MASTER | 8.b.d.0.1.0.0.2.ip6.arpa (IPv6)     | admin"
    echo "  MASTER | xn--verstt-eua3l.info (IDN)         | manager"
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
                echo "  MYSQL_USER         MySQL username (default: pdns)"
                echo "  MYSQL_PASSWORD     MySQL password (default: poweradmin)"
                echo "  MYSQL_CONTAINER    MySQL container name (default: mariadb)"
                echo "  PGSQL_USER         PostgreSQL username (default: pdns)"
                echo "  PGSQL_PASSWORD     PostgreSQL password (default: poweradmin)"
                echo "  PGSQL_CONTAINER    PostgreSQL container name (default: postgres)"
                echo "  SQLITE_CONTAINER   SQLite container name (default: sqlite)"
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

    # Show summary
    show_summary

    # Final status
    echo ""
    if [ $fail_count -eq 0 ]; then
        echo -e "${GREEN}✅ Import completed successfully!${NC}"
        exit 0
    else
        echo -e "${YELLOW}⚠️  Import completed with some failures${NC}"
        echo -e "   Success: $success_count, Failed: $fail_count"
        exit 1
    fi
}

main "$@"
