#!/bin/bash
# =============================================================================
# Import Test Data Script for Poweradmin
# =============================================================================
#
# Purpose: Import comprehensive test data into running Docker databases
#
# This script imports:
# - Test users with different permission levels (password: Poweradmin123)
# - Test domains (master zones)
# - Zone ownership records
# - Comprehensive DNS records for UI testing
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
# SCRIPT_DIR is .devcontainer/scripts, so go up one level to .devcontainer
DEVCONTAINER_DIR="$(dirname "$SCRIPT_DIR")"
SQL_DIR="$DEVCONTAINER_DIR/sql"

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Database credentials (adjust if needed)
# Default to pdns user as configured in .devcontainer/.env
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
SQLITE_DB_PATH="${SQLITE_DB_PATH:-/data/pdns.db}"

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}  Poweradmin Test Data Import${NC}"
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

    docker exec -i "$MYSQL_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" << 'EOSQL'
USE poweradmin;

-- Delete zone template associations and sync data
DELETE FROM zone_template_sync;
DELETE FROM records_zone_templ;

-- Delete zone ownership records
DELETE FROM zones;

-- Delete zone template records and templates
DELETE FROM zone_templ_records;
DELETE FROM zone_templ;

-- Delete user-related data (cascades handle some, but be explicit)
DELETE FROM oidc_user_links;
DELETE FROM saml_user_links;
DELETE FROM user_agreements;
DELETE FROM user_mfa;
DELETE FROM user_preferences;
DELETE FROM api_keys;
DELETE FROM login_attempts;
DELETE FROM password_reset_tokens;
DELETE FROM username_recovery_requests;

-- Delete test users (keep admin if exists)
DELETE FROM users WHERE username != 'admin';

-- Delete permission template items for non-default templates
DELETE FROM perm_templ_items WHERE templ_id > 1;

-- Delete permission templates (keep Administrator)
DELETE FROM perm_templ WHERE id > 1;

-- Clear logs
DELETE FROM log_users;
DELETE FROM log_zones;

USE pdns;

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
-- Delete zone template associations and sync data
DELETE FROM zone_template_sync;
DELETE FROM records_zone_templ;

-- Delete zone ownership records
DELETE FROM zones;

-- Delete zone template records and templates
DELETE FROM zone_templ_records;
DELETE FROM zone_templ;

-- Delete user-related data
DELETE FROM oidc_user_links;
DELETE FROM saml_user_links;
DELETE FROM user_agreements;
DELETE FROM user_mfa;
DELETE FROM user_preferences;
DELETE FROM api_keys;
DELETE FROM login_attempts;
DELETE FROM password_reset_tokens;
DELETE FROM username_recovery_requests;

-- Delete test users (keep admin if exists)
DELETE FROM users WHERE username != 'admin';

-- Delete permission template items for non-default templates
DELETE FROM perm_templ_items WHERE templ_id > 1;

-- Delete permission templates (keep Administrator)
DELETE FROM perm_templ WHERE id > 1;

-- Clear logs
DELETE FROM log_users;
DELETE FROM log_zones;

-- Delete all records
DELETE FROM records;

-- Delete all domains
DELETE FROM domains;

-- Reset sequences
SELECT setval('perm_templ_id_seq', 1);
SELECT setval('perm_templ_items_id_seq', COALESCE((SELECT MAX(id) FROM perm_templ_items), 1));
SELECT setval('users_id_seq', COALESCE((SELECT MAX(id) FROM users), 1));
SELECT setval('domains_id_seq', 1);
SELECT setval('records_id_seq', 1);
SELECT setval('zones_id_seq', 1);
SELECT setval('zone_templ_id_seq', 1);
SELECT setval('zone_templ_records_id_seq', 1);
SELECT setval('records_zone_templ_id_seq', 1);
SELECT setval('log_users_id_seq1', 1);
SELECT setval('log_zones_id_seq1', 1);
SELECT setval('api_keys_id_seq', 1);
SELECT setval('user_mfa_id_seq', 1);
SELECT setval('login_attempts_id_seq', 1);
EOSQL

    echo -e "${GREEN}✅ PostgreSQL cleaned${NC}"
}

# Function to clean SQLite test data
clean_sqlite() {
    echo -e "${YELLOW}🧹 Cleaning SQLite test data...${NC}"

    if ! check_container "$SQLITE_CONTAINER"; then
        echo -e "${RED}❌ Container '$SQLITE_CONTAINER' is not running${NC}"
        return 1
    fi

    docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" << 'EOSQL'
-- Attach PowerDNS database
ATTACH DATABASE '/data/pdns.db' AS pdns;

-- Delete zone template associations and sync data
DELETE FROM zone_template_sync;
DELETE FROM records_zone_templ;

-- Delete zone ownership records
DELETE FROM zones;

-- Delete zone template records and templates
DELETE FROM zone_templ_records;
DELETE FROM zone_templ;

-- Delete user-related data
DELETE FROM oidc_user_links;
DELETE FROM saml_user_links;
DELETE FROM user_agreements;
DELETE FROM user_mfa;
DELETE FROM user_preferences;
DELETE FROM api_keys;
DELETE FROM login_attempts;
DELETE FROM password_reset_tokens;
DELETE FROM username_recovery_requests;

-- Delete test users (keep admin if exists)
DELETE FROM users WHERE username != 'admin';

-- Delete permission template items for non-default templates
DELETE FROM perm_templ_items WHERE templ_id > 1;

-- Delete permission templates (keep Administrator)
DELETE FROM perm_templ WHERE id > 1;

-- Clear logs
DELETE FROM log_users;
DELETE FROM log_zones;

-- Delete all records from PowerDNS database
DELETE FROM pdns.records;

-- Delete all domains from PowerDNS database
DELETE FROM pdns.domains;
EOSQL

    echo -e "${GREEN}✅ SQLite cleaned${NC}"
}

# Function to import MySQL/MariaDB data
import_mysql() {
    echo -e "${YELLOW}📦 Importing to MySQL/MariaDB...${NC}"

    if ! check_container "$MYSQL_CONTAINER"; then
        echo -e "${RED}❌ Container '$MYSQL_CONTAINER' is not running${NC}"
        return 1
    fi

    # Use the combined SQL file that handles both poweradmin and pdns databases
    if [ ! -f "$SQL_DIR/test-users-permissions-mysql-combined.sql" ]; then
        echo -e "${RED}❌ MySQL SQL file not found: $SQL_DIR/test-users-permissions-mysql-combined.sql${NC}"
        return 1
    fi

    # Check if Poweradmin schema exists, import if needed
    local has_users_table=$(docker exec "$MYSQL_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$MYSQL_DATABASE' AND table_name='users';" 2>/dev/null || echo "0")

    if [ "$has_users_table" = "0" ]; then
        echo -e "${YELLOW}📦 Poweradmin schema not found, importing...${NC}"
        local poweradmin_schema="$DEVCONTAINER_DIR/../sql/poweradmin-mysql-db-structure.sql"
        if [ -f "$poweradmin_schema" ]; then
            if docker exec -i "$MYSQL_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < "$poweradmin_schema" 2>/dev/null; then
                echo -e "${GREEN}✅ Poweradmin schema imported${NC}"
            else
                echo -e "${RED}❌ Poweradmin schema import failed${NC}"
                return 1
            fi
        else
            echo -e "${RED}❌ Poweradmin schema file not found at $poweradmin_schema${NC}"
            return 1
        fi
    fi

    # Capture output and exit status separately to avoid grep masking the real status
    # Note: We connect without specifying a database since the SQL uses USE statements
    local output
    local exit_code
    output=$(docker exec -i "$MYSQL_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" < "$SQL_DIR/test-users-permissions-mysql-combined.sql" 2>&1)
    exit_code=$?

    if [ $exit_code -eq 0 ]; then
        echo -e "${GREEN}✅ MySQL/MariaDB users and zones imported${NC}"

        # Import comprehensive DNS records if the file exists
        if [ -f "$SQL_DIR/test-dns-records-mysql.sql" ]; then
            echo -e "${YELLOW}📦 Importing comprehensive DNS records...${NC}"
            output=$(docker exec -i "$MYSQL_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_PDNS_DATABASE" < "$SQL_DIR/test-dns-records-mysql.sql" 2>&1)
            exit_code=$?

            if [ $exit_code -eq 0 ]; then
                echo -e "${GREEN}✅ MySQL/MariaDB DNS records imported${NC}"
            else
                echo -e "${YELLOW}⚠️  DNS records import had issues (may already exist)${NC}"
            fi
        fi

        # Import reverse zones and zone templates if the file exists
        if [ -f "$SQL_DIR/test-reverse-zones-templates-mysql.sql" ]; then
            echo -e "${YELLOW}📦 Importing reverse zones and zone templates...${NC}"
            output=$(docker exec -i "$MYSQL_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" < "$SQL_DIR/test-reverse-zones-templates-mysql.sql" 2>&1)
            exit_code=$?

            if [ $exit_code -eq 0 ]; then
                echo -e "${GREEN}✅ MySQL/MariaDB reverse zones and templates imported${NC}"
            else
                echo -e "${YELLOW}⚠️  Reverse zones/templates import had issues (may already exist)${NC}"
            fi
        fi

        return 0
    else
        echo -e "${RED}❌ MySQL/MariaDB import failed${NC}"
        # Show error output (filter out password warning)
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

    # Check if Poweradmin schema exists, import if needed
    local has_users_table=$(docker exec -e PGPASSWORD="$PGSQL_PASSWORD" "$PGSQL_CONTAINER" psql -U "$PGSQL_USER" -d "$PGSQL_DATABASE" -tAc "SELECT COUNT(*) FROM information_schema.tables WHERE table_name='users';" 2>/dev/null || echo "0")

    if [ "$has_users_table" = "0" ]; then
        echo -e "${YELLOW}📦 Poweradmin schema not found, importing...${NC}"
        local poweradmin_schema="$DEVCONTAINER_DIR/../sql/poweradmin-pgsql-db-structure.sql"
        if [ -f "$poweradmin_schema" ]; then
            if docker exec -i -e PGPASSWORD="$PGSQL_PASSWORD" "$PGSQL_CONTAINER" psql -U "$PGSQL_USER" -d "$PGSQL_DATABASE" < "$poweradmin_schema" > /dev/null 2>&1; then
                echo -e "${GREEN}✅ Poweradmin schema imported${NC}"
            else
                echo -e "${RED}❌ Poweradmin schema import failed${NC}"
                return 1
            fi
        else
            echo -e "${RED}❌ Poweradmin schema file not found at $poweradmin_schema${NC}"
            return 1
        fi
    fi

    # Pass PGPASSWORD into the container environment
    if docker exec -i -e PGPASSWORD="$PGSQL_PASSWORD" "$PGSQL_CONTAINER" psql -U "$PGSQL_USER" -d "$PGSQL_DATABASE" < "$SQL_DIR/test-users-permissions-pgsql.sql" > /dev/null 2>&1; then
        echo -e "${GREEN}✅ PostgreSQL users and zones imported${NC}"

        # Import comprehensive DNS records if the file exists
        if [ -f "$SQL_DIR/test-dns-records-pgsql.sql" ]; then
            echo -e "${YELLOW}📦 Importing comprehensive DNS records...${NC}"
            if docker exec -i -e PGPASSWORD="$PGSQL_PASSWORD" "$PGSQL_CONTAINER" psql -U "$PGSQL_USER" -d "$PGSQL_DATABASE" < "$SQL_DIR/test-dns-records-pgsql.sql" > /dev/null 2>&1; then
                echo -e "${GREEN}✅ PostgreSQL DNS records imported${NC}"
            else
                echo -e "${YELLOW}⚠️  DNS records import had issues (may already exist)${NC}"
            fi
        fi

        # Import reverse zones and zone templates if the file exists
        if [ -f "$SQL_DIR/test-reverse-zones-templates-pgsql.sql" ]; then
            echo -e "${YELLOW}📦 Importing reverse zones and zone templates...${NC}"
            if docker exec -i -e PGPASSWORD="$PGSQL_PASSWORD" "$PGSQL_CONTAINER" psql -U "$PGSQL_USER" -d "$PGSQL_DATABASE" < "$SQL_DIR/test-reverse-zones-templates-pgsql.sql" > /dev/null 2>&1; then
                echo -e "${GREEN}✅ PostgreSQL reverse zones and templates imported${NC}"
            else
                echo -e "${YELLOW}⚠️  Reverse zones/templates import had issues (may already exist)${NC}"
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

    # Check if Poweradmin schema exists, import if needed
    local has_users_table=$(docker exec "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" "SELECT name FROM sqlite_master WHERE type='table' AND name='users';" 2>/dev/null || echo "")

    if [ -z "$has_users_table" ]; then
        echo -e "${YELLOW}📦 Poweradmin schema not found, importing...${NC}"
        local poweradmin_schema="$DEVCONTAINER_DIR/../sql/poweradmin-sqlite-db-structure.sql"
        if [ -f "$poweradmin_schema" ]; then
            if docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$poweradmin_schema" > /dev/null 2>&1; then
                echo -e "${GREEN}✅ Poweradmin schema imported${NC}"
            else
                echo -e "${RED}❌ Poweradmin schema import failed${NC}"
                return 1
            fi
        else
            echo -e "${RED}❌ Poweradmin schema file not found at $poweradmin_schema${NC}"
            return 1
        fi
    fi

    # Execute SQL file directly (the ATTACH command is in the SQL file)
    # The script attaches /data/db/powerdns.db as 'pdns' to access domains/records tables
    if docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$SQL_DIR/test-users-permissions-sqlite.sql" > /dev/null 2>&1; then
        echo -e "${GREEN}✅ SQLite users and zones imported${NC}"

        # Import comprehensive DNS records if the file exists
        if [ -f "$SQL_DIR/test-dns-records-sqlite.sql" ]; then
            echo -e "${YELLOW}📦 Importing comprehensive DNS records...${NC}"
            if docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$SQL_DIR/test-dns-records-sqlite.sql" > /dev/null 2>&1; then
                echo -e "${GREEN}✅ SQLite DNS records imported${NC}"
            else
                echo -e "${YELLOW}⚠️  DNS records import had issues (may already exist)${NC}"
            fi
        fi

        # Import reverse zones and zone templates if the file exists
        if [ -f "$SQL_DIR/test-reverse-zones-templates-sqlite.sql" ]; then
            echo -e "${YELLOW}📦 Importing reverse zones and zone templates...${NC}"
            if docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$SQL_DIR/test-reverse-zones-templates-sqlite.sql" > /dev/null 2>&1; then
                echo -e "${GREEN}✅ SQLite reverse zones and templates imported${NC}"
            else
                echo -e "${YELLOW}⚠️  Reverse zones/templates import had issues (may already exist)${NC}"
            fi
        fi

        return 0
    else
        echo -e "${RED}❌ SQLite import failed${NC}"
        echo -e "${YELLOW}Note: Ensure PowerDNS database exists at /data/pdns.db in the container${NC}"
        return 1
    fi
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
                echo "  --help, -h    Show this help message"
                echo ""
                echo "If no database options specified, imports to all available databases"
                echo ""
                echo "Examples:"
                echo "  $0                    # Import to all databases"
                echo "  $0 --mysql            # Import to MySQL only"
                echo "  $0 --clean --mysql    # Clean and import to MySQL"
                echo "  $0 --clean            # Clean and import to all databases"
                echo ""
                echo "Environment variables:"
                echo "  MYSQL_USER          MySQL username (default: pdns)"
                echo "  MYSQL_PASSWORD      MySQL password (default: poweradmin)"
                echo "  MYSQL_DATABASE      Poweradmin database (default: poweradmin)"
                echo "  MYSQL_PDNS_DATABASE PowerDNS database (default: pdns)"
                echo "  MYSQL_CONTAINER     MySQL container name (default: mariadb)"
                echo "  PGSQL_USER          PostgreSQL username (default: pdns)"
                echo "  PGSQL_PASSWORD      PostgreSQL password (default: poweradmin)"
                echo "  PGSQL_DATABASE      PostgreSQL database (default: pdns)"
                echo "  PGSQL_CONTAINER     PostgreSQL container name (default: postgres)"
                echo "  SQLITE_CONTAINER    SQLite container name (default: sqlite)"
                echo "  SQLITE_DB_PATH      SQLite database path (default: /data/pdns.db)"
                exit 0
                ;;
            *)
                echo -e "${RED}Unknown option: $1${NC}"
                echo "Use --help for usage information"
                exit 1
                ;;
        esac
    done

    # If no specific database selected, import to all
    if [ "$import_all" = true ]; then
        import_mysql=true
        import_pgsql=true
        import_sqlite=true
    fi

    local success_count=0
    local fail_count=0

    # Execute clean operations if requested
    if [ "$do_clean" = true ]; then
        echo -e "${BLUE}Cleaning databases before import...${NC}"
        echo ""

        if [ "$import_mysql" = true ]; then
            clean_mysql || true
            echo ""
        fi

        if [ "$import_pgsql" = true ]; then
            clean_pgsql || true
            echo ""
        fi

        if [ "$import_sqlite" = true ]; then
            clean_sqlite || true
            echo ""
        fi
    fi

    # Execute imports
    if [ "$import_mysql" = true ]; then
        if import_mysql; then
            ((success_count++))
        else
            ((fail_count++))
        fi
        echo ""
    fi

    if [ "$import_pgsql" = true ]; then
        if import_pgsql; then
            ((success_count++))
        else
            ((fail_count++))
        fi
        echo ""
    fi

    if [ "$import_sqlite" = true ]; then
        if import_sqlite; then
            ((success_count++))
        else
            ((fail_count++))
        fi
        echo ""
    fi

    # Summary
    echo -e "${BLUE}================================================${NC}"
    echo -e "${BLUE}  Import Summary${NC}"
    echo -e "${BLUE}================================================${NC}"
    echo -e "${GREEN}✅ Successful: $success_count${NC}"
    if [ $fail_count -gt 0 ]; then
        echo -e "${RED}❌ Failed: $fail_count${NC}"
    fi
    echo ""

    if [ $fail_count -eq 0 ]; then
        echo -e "${GREEN}🎉 All imports completed successfully!${NC}"
        echo ""
        echo -e "${BLUE}Test credentials:${NC}"
        echo "  Username: admin, manager, client, viewer, noperm, inactive"
        echo "  Password: Poweradmin123"
        echo ""
        echo -e "${BLUE}Test zones:${NC}"
        echo "  Forward zones:"
        echo "  - admin-zone.example.com (owner: admin)"
        echo "  - manager-zone.example.com (owner: manager) - with comprehensive DNS records"
        echo "  - client-zone.example.com (owner: client) - with comprehensive DNS records"
        echo "  - shared-zone.example.com (owners: manager, client)"
        echo ""
        echo "  Reverse zones:"
        echo "  - 2.0.192.in-addr.arpa (IPv4, owners: admin, manager)"
        echo "  - 8.b.d.0.1.0.0.2.ip6.arpa (IPv6, owner: admin)"
        echo ""
        echo -e "${BLUE}Zone Templates:${NC}"
        echo "  - Standard Web Zone (owner: admin) - www, mail, ftp, MX"
        echo "  - Mail Server Zone (owner: admin) - MX, SPF"
        echo "  - Minimal Zone (owner: admin) - empty"
        echo "  - Manager Template (owner: manager) - empty"
        echo ""
        echo -e "${BLUE}DNS Records (manager-zone and client-zone):${NC}"
        echo "  - SOA, NS: Standard zone records"
        echo "  - A: 7 records (www, mail, ftp, blog, shop, api, root)"
        echo "  - AAAA: 3 records (IPv6 support)"
        echo "  - MX: 2 records (mail servers with priorities)"
        echo "  - TXT: 3 records (SPF, DMARC, DKIM - long content for UI testing)"
        echo "  - CNAME: 3 records (cdn, docs, webmail)"
        echo "  - SRV: 2 records (XMPP, SIP)"
        echo "  - CAA: 2 records (certificate authority)"
        echo "  - Disabled: 1 record (for testing disabled state)"
        echo "  Total: ~26 records per zone for comprehensive UI testing"
        echo ""
        exit 0
    else
        echo -e "${YELLOW}⚠️  Some imports failed. Check the errors above.${NC}"
        exit 1
    fi
}

main "$@"
