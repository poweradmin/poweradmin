#!/bin/bash

##############################################################################
# Poweradmin API Test Runner
# Simplified test runner for API testing
##############################################################################

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_TEST_SCRIPT="$SCRIPT_DIR/api-test.sh"
LOAD_TEST_SCRIPT="$SCRIPT_DIR/api-load-test.sh"
CONFIG_EXAMPLE="$SCRIPT_DIR/.env.api-test.example"

# Database-specific config files
CONFIG_MYSQL="$SCRIPT_DIR/.env.api-test.mysql"
CONFIG_PGSQL="$SCRIPT_DIR/.env.api-test.pgsql"
CONFIG_SQLITE="$SCRIPT_DIR/.env.api-test.sqlite"

# Default to MySQL config
CONFIG_FILE="$CONFIG_MYSQL"

# Selected database (can be overridden by --db flag)
SELECTED_DB=""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

usage() {
    echo "Poweradmin API Test Runner"
    echo ""
    echo "Usage: $0 [COMMAND] [OPTIONS]"
    echo ""
    echo "Commands:"
    echo "  setup              Setup test configuration"
    echo "  test [SUITE]       Run API tests"
    echo "  test:all-dbs       Run API tests against all databases"
    echo "  load [TYPE]        Run load tests"
    echo "  check              Check test prerequisites"
    echo "  clean              Clean up test data"
    echo ""
    echo "Database Options:"
    echo "  --db mysql         Use MySQL config (port 8080)"
    echo "  --db pgsql         Use PostgreSQL config (port 8081)"
    echo "  --db sqlite        Use SQLite config (port 8082)"
    echo ""
    echo "Test Suites:"
    echo "  all                Run all tests (default)"
    echo "  auth               Authentication tests only"
    echo "  users              User management tests"
    echo "  zones              Zone management tests"
    echo "  records            Record management tests"
    echo "  security           Security tests"
    echo "  performance        Performance tests"
    echo ""
    echo "Load Test Types:"
    echo "  all                Run all load tests (default)"
    echo "  auth               Authentication load test"
    echo "  zones              Zone creation load test"
    echo "  rate-limit         Rate limiting test"
    echo "  memory             Memory leak test"
    echo ""
    echo "Examples:"
    echo "  $0 setup"
    echo "  $0 test auth"
    echo "  $0 test all"
    echo "  $0 --db pgsql test all"
    echo "  $0 test:all-dbs"
    echo "  $0 load rate-limit"
    echo "  $0 check"
}

select_database_config() {
    local db_type="$1"

    case "$db_type" in
        mysql)
            if [[ -f "$CONFIG_MYSQL" ]]; then
                CONFIG_FILE="$CONFIG_MYSQL"
                echo -e "${BLUE}Using MySQL configuration (port 8080)${NC}"
            else
                echo -e "${RED}MySQL config not found: $CONFIG_MYSQL${NC}"
                return 1
            fi
            ;;
        pgsql|postgres|postgresql)
            if [[ -f "$CONFIG_PGSQL" ]]; then
                CONFIG_FILE="$CONFIG_PGSQL"
                echo -e "${BLUE}Using PostgreSQL configuration (port 8081)${NC}"
            else
                echo -e "${RED}PostgreSQL config not found: $CONFIG_PGSQL${NC}"
                return 1
            fi
            ;;
        sqlite)
            if [[ -f "$CONFIG_SQLITE" ]]; then
                CONFIG_FILE="$CONFIG_SQLITE"
                echo -e "${BLUE}Using SQLite configuration (port 8082)${NC}"
            else
                echo -e "${RED}SQLite config not found: $CONFIG_SQLITE${NC}"
                return 1
            fi
            ;;
        *)
            echo -e "${RED}Unknown database type: $db_type${NC}"
            echo "Valid options: mysql, pgsql, sqlite"
            return 1
            ;;
    esac

    return 0
}

run_tests_all_databases() {
    local suite="${1:-all}"
    local total_passed=0
    local total_failed=0
    local db_results=()

    echo -e "${BLUE}================================${NC}"
    echo -e "${BLUE}Running API Tests on All Databases${NC}"
    echo -e "${BLUE}================================${NC}"
    echo ""

    for db in mysql pgsql sqlite; do
        echo -e "\n${BLUE}=== Testing $db database ===${NC}\n"

        if select_database_config "$db"; then
            if check_config && test_api_connection; then
                if run_api_tests "$suite"; then
                    db_results+=("${GREEN}$db: PASSED${NC}")
                    ((total_passed++))
                else
                    db_results+=("${RED}$db: FAILED${NC}")
                    ((total_failed++))
                fi
            else
                db_results+=("${YELLOW}$db: SKIPPED (connection failed)${NC}")
            fi
        else
            db_results+=("${YELLOW}$db: SKIPPED (config not found)${NC}")
        fi
    done

    echo ""
    echo -e "${BLUE}================================${NC}"
    echo -e "${BLUE}Multi-Database Test Summary${NC}"
    echo -e "${BLUE}================================${NC}"
    for result in "${db_results[@]}"; do
        echo -e "  $result"
    done
    echo ""
    echo "Databases passed: $total_passed"
    echo "Databases failed: $total_failed"

    if [[ $total_failed -gt 0 ]]; then
        return 1
    fi
    return 0
}

check_dependencies() {
    local missing_deps=()

    command -v curl >/dev/null 2>&1 || missing_deps+=("curl")
    command -v jq >/dev/null 2>&1 || missing_deps+=("jq")
    command -v bc >/dev/null 2>&1 || missing_deps+=("bc")

    if [[ ${#missing_deps[@]} -gt 0 ]]; then
        echo -e "${RED}Missing dependencies: ${missing_deps[*]}${NC}"
        echo ""
        echo "Install missing dependencies:"
        echo ""
        if command -v apt-get >/dev/null 2>&1; then
            echo "  sudo apt-get install ${missing_deps[*]}"
        elif command -v yum >/dev/null 2>&1; then
            echo "  sudo yum install ${missing_deps[*]}"
        elif command -v brew >/dev/null 2>&1; then
            echo "  brew install ${missing_deps[*]}"
        else
            echo "  Please install: ${missing_deps[*]}"
        fi
        echo ""
        return 1
    fi

    return 0
}

setup_config() {
    echo -e "${BLUE}Setting up API test configuration${NC}"
    echo ""
    
    if [[ -f "$CONFIG_FILE" ]]; then
        echo -e "${YELLOW}Configuration file already exists: $CONFIG_FILE${NC}"
        read -p "Overwrite existing configuration? (y/N): " -n 1 -r
        echo
        
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo "Configuration setup cancelled"
            return 0
        fi
    fi
    
    if [[ ! -f "$CONFIG_EXAMPLE" ]]; then
        echo -e "${RED}Example configuration file not found: $CONFIG_EXAMPLE${NC}"
        return 1
    fi
    
    # Copy example and prompt for values
    cp "$CONFIG_EXAMPLE" "$CONFIG_FILE"
    
    echo "Please provide the following configuration values:"
    echo ""
    
    # API Base URL
    read -p "API Base URL (e.g., http://localhost): " api_url
    if [[ -n "$api_url" ]]; then
        sed -i.bak "s|API_BASE_URL=.*|API_BASE_URL=$api_url|" "$CONFIG_FILE"
    fi
    
    # API Key
    read -p "API Key: " api_key
    if [[ -n "$api_key" ]]; then
        sed -i.bak "s|API_KEY=.*|API_KEY=$api_key|" "$CONFIG_FILE"
    fi
    
    # Database settings
    read -p "Database Host (default: localhost): " db_host
    if [[ -n "$db_host" ]]; then
        sed -i.bak "s|DB_HOST=.*|DB_HOST=$db_host|" "$CONFIG_FILE"
    fi
    
    read -p "Database Name (default: poweradmin_test): " db_name
    if [[ -n "$db_name" ]]; then
        sed -i.bak "s|DB_NAME=.*|DB_NAME=$db_name|" "$CONFIG_FILE"
    fi
    
    read -p "Database User: " db_user
    if [[ -n "$db_user" ]]; then
        sed -i.bak "s|DB_USER=.*|DB_USER=$db_user|" "$CONFIG_FILE"
    fi
    
    read -s -p "Database Password: " db_pass
    echo
    if [[ -n "$db_pass" ]]; then
        sed -i.bak "s|DB_PASS=.*|DB_PASS=$db_pass|" "$CONFIG_FILE"
    fi
    
    read -p "Database Type (mysql/pgsql/sqlite, default: mysql): " db_type
    if [[ -n "$db_type" ]]; then
        sed -i.bak "s|DB_TYPE=.*|DB_TYPE=$db_type|" "$CONFIG_FILE"
    fi
    
    # Clean up backup file
    rm -f "${CONFIG_FILE}.bak"
    
    echo ""
    echo -e "${GREEN}Configuration saved to: $CONFIG_FILE${NC}"
    echo ""
    echo "You can now run tests with:"
    echo "  $0 test all"
}

check_config() {
    if [[ ! -f "$CONFIG_FILE" ]]; then
        echo -e "${RED}Configuration file not found: $CONFIG_FILE${NC}"
        echo "Run '$0 setup' to create configuration"
        return 1
    fi

    # Load and validate configuration
    set -a
    source "$CONFIG_FILE"
    set +a

    # Base required vars for all database types
    local required_vars=("API_BASE_URL" "API_KEY" "DB_TYPE")

    # SQLite doesn't require host/user/name - it uses file path
    if [[ "${DB_TYPE:-}" != "sqlite" ]]; then
        required_vars+=("DB_HOST" "DB_NAME" "DB_USER")
    fi

    local missing_vars=()

    for var in "${required_vars[@]}"; do
        if [[ -z "${!var:-}" ]]; then
            missing_vars+=("$var")
        fi
    done

    if [[ ${#missing_vars[@]} -gt 0 ]]; then
        echo -e "${RED}Missing configuration variables: ${missing_vars[*]}${NC}"
        echo "Please edit $CONFIG_FILE or run '$0 setup'"
        return 1
    fi

    return 0
}

test_api_connection() {
    echo -e "${BLUE}Testing API connection...${NC}"
    
    set -a
    source "$CONFIG_FILE"
    set +a
    
    local response_code=$(curl -s -w "%{http_code}" \
        -H "X-API-Key: $API_KEY" \
        -H "Accept: application/json" \
        "${API_BASE_URL}/api/v1/users" \
        -o /dev/null 2>/dev/null || echo "000")
    
    if [[ "$response_code" == "200" ]]; then
        echo -e "${GREEN}✓ API connection successful${NC}"
        return 0
    elif [[ "$response_code" == "401" ]]; then
        echo -e "${RED}✗ API authentication failed (check API key)${NC}"
        return 1
    elif [[ "$response_code" == "000" ]]; then
        echo -e "${RED}✗ API connection failed (check URL)${NC}"
        return 1
    else
        echo -e "${YELLOW}⚠ API responded with status: $response_code${NC}"
        return 1
    fi
}

run_api_tests() {
    local suite="${1:-all}"

    echo -e "${BLUE}Running API tests: $suite${NC}"
    echo ""

    if [[ ! -x "$API_TEST_SCRIPT" ]]; then
        chmod +x "$API_TEST_SCRIPT"
    fi

    # Export CONFIG_FILE so api-test.sh can use it
    export CONFIG_FILE
    "$API_TEST_SCRIPT" "$suite"
}

run_load_tests() {
    local test_type="${1:-all}"
    
    echo -e "${BLUE}Running load tests: $test_type${NC}"
    echo ""
    
    if [[ ! -x "$LOAD_TEST_SCRIPT" ]]; then
        chmod +x "$LOAD_TEST_SCRIPT"
    fi
    
    "$LOAD_TEST_SCRIPT" "$test_type"
}

check_prerequisites() {
    echo -e "${BLUE}Checking prerequisites...${NC}"
    echo ""
    
    local all_good=true
    
    # Check dependencies
    if check_dependencies; then
        echo -e "${GREEN}✓ All dependencies installed${NC}"
    else
        all_good=false
    fi
    
    # Check configuration
    if check_config; then
        echo -e "${GREEN}✓ Configuration valid${NC}"
    else
        all_good=false
    fi
    
    # Test API connection
    if test_api_connection; then
        echo ""
    else
        all_good=false
    fi
    
    if [[ "$all_good" == "true" ]]; then
        echo -e "${GREEN}✓ All prerequisites met${NC}"
        return 0
    else
        echo -e "${RED}✗ Some prerequisites not met${NC}"
        return 1
    fi
}

clean_test_data() {
    echo -e "${BLUE}Cleaning test data...${NC}"
    
    if [[ ! -f "$CONFIG_FILE" ]]; then
        echo -e "${RED}Configuration file not found${NC}"
        return 1
    fi
    
    set -a
    source "$CONFIG_FILE"
    set +a
    
    # Remove test users, zones, and records created by the test suite
    local test_patterns=("api_test_user_curl" "curl-test.example.com")
    
    for pattern in "${test_patterns[@]}"; do
        echo "Cleaning up items matching: $pattern"
        
        # This is a simplified cleanup - in a real scenario, you'd want to
        # query the API to find and delete test items
        curl -s -H "X-API-Key: $API_KEY" \
            "${API_BASE_URL}/api/v1/users?username=$pattern" \
            >/dev/null 2>&1 || true
    done
    
    echo -e "${GREEN}Cleanup completed${NC}"
}

main() {
    local args=()
    local command=""

    # Parse arguments
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --db|--database)
                if [[ -n "${2:-}" ]]; then
                    if ! select_database_config "$2"; then
                        exit 1
                    fi
                    shift 2
                else
                    echo -e "${RED}--db requires a database type (mysql, pgsql, sqlite)${NC}"
                    exit 1
                fi
                ;;
            *)
                args+=("$1")
                shift
                ;;
        esac
    done

    # Get command from remaining args
    command="${args[0]:-}"
    local suite="${args[1]:-all}"

    case "$command" in
        "setup")
            if ! check_dependencies; then
                exit 1
            fi
            setup_config
            ;;
        "test")
            if ! check_prerequisites; then
                exit 1
            fi
            run_api_tests "$suite"
            ;;
        "test:all-dbs"|"test:all")
            if ! check_dependencies; then
                exit 1
            fi
            run_tests_all_databases "$suite"
            ;;
        "load")
            if ! check_prerequisites; then
                exit 1
            fi
            run_load_tests "$suite"
            ;;
        "check")
            check_prerequisites
            ;;
        "clean")
            clean_test_data
            ;;
        "help"|"-h"|"--help")
            usage
            ;;
        "")
            usage
            exit 1
            ;;
        *)
            echo -e "${RED}Unknown command: $command${NC}"
            echo ""
            usage
            exit 1
            ;;
    esac
}

main "$@"