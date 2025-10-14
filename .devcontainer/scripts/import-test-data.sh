#!/bin/bash
# Import test data into running Docker databases
# This script imports test users, permissions, and domains into all database types

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
SQLITE_DB_PATH="${SQLITE_DB_PATH:-/data/poweradmin.db}"

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

    # Capture output and exit status separately to avoid grep masking the real status
    # Note: We connect without specifying a database since the SQL uses USE statements
    local output
    local exit_code
    output=$(docker exec -i "$MYSQL_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" < "$SQL_DIR/test-users-permissions-mysql-combined.sql" 2>&1)
    exit_code=$?

    if [ $exit_code -eq 0 ]; then
        echo -e "${GREEN}✅ MySQL/MariaDB import successful${NC}"
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

    # Pass PGPASSWORD into the container environment
    if docker exec -i -e PGPASSWORD="$PGSQL_PASSWORD" "$PGSQL_CONTAINER" psql -U "$PGSQL_USER" -d "$PGSQL_DATABASE" < "$SQL_DIR/test-users-permissions-pgsql.sql" > /dev/null 2>&1; then
        echo -e "${GREEN}✅ PostgreSQL import successful${NC}"
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

    # Execute SQL file directly (the ATTACH command is in the SQL file)
    # The script attaches /data/db/powerdns.db as 'pdns' to access domains/records tables
    if docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$SQL_DIR/test-users-permissions-sqlite.sql" > /dev/null 2>&1; then
        echo -e "${GREEN}✅ SQLite import successful${NC}"
        return 0
    else
        echo -e "${RED}❌ SQLite import failed${NC}"
        echo -e "${YELLOW}Note: Ensure PowerDNS database exists at /data/db/powerdns.db in the container${NC}"
        return 1
    fi
}

# Main import logic
main() {
    local import_mysql=false
    local import_pgsql=false
    local import_sqlite=false
    local import_all=true

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
            --help|-h)
                echo "Usage: $0 [OPTIONS]"
                echo ""
                echo "Import test data into Poweradmin databases"
                echo ""
                echo "Options:"
                echo "  --mysql       Import to MySQL/MariaDB only"
                echo "  --pgsql       Import to PostgreSQL only"
                echo "  --sqlite      Import to SQLite only"
                echo "  --help, -h    Show this help message"
                echo ""
                echo "If no options specified, imports to all available databases"
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
                echo "  SQLITE_DB_PATH      SQLite database path (default: /data/poweradmin.db)"
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
        echo "  Password: poweradmin123"
        echo ""
        echo -e "${BLUE}Test zones:${NC}"
        echo "  - admin-zone.example.com (owner: admin)"
        echo "  - manager-zone.example.com (owner: manager)"
        echo "  - client-zone.example.com (owner: client)"
        echo "  - shared-zone.example.com (owners: manager, client)"
        echo ""
        exit 0
    else
        echo -e "${YELLOW}⚠️  Some imports failed. Check the errors above.${NC}"
        exit 1
    fi
}

main "$@"
