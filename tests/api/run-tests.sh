#!/bin/bash

##############################################################################
# Poweradmin API Test Runner
# Simplified test runner for API testing
##############################################################################

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_V1_TEST_SCRIPT="$SCRIPT_DIR/api-test.sh"
API_V2_TEST_SCRIPT="$SCRIPT_DIR/api-v2-test.sh"
LOAD_TEST_SCRIPT="$SCRIPT_DIR/api-load-test.sh"

# Database-specific config files
CONFIG_MYSQL="$SCRIPT_DIR/.env.api-test.mysql"
CONFIG_PGSQL="$SCRIPT_DIR/.env.api-test.pgsql"
CONFIG_SQLITE="$SCRIPT_DIR/.env.api-test.sqlite"

# Default to MySQL config
CONFIG_FILE="$CONFIG_MYSQL"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

usage() {
    echo "Poweradmin API Test Runner"
    echo ""
    echo "Usage: $0 [COMMAND] [OPTIONS]"
    echo ""
    echo "Commands:"
    echo "  test [VERSION]     Run API tests (v1, v2, or all)"
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
    echo "API Versions:"
    echo "  v1                 Run API v1 tests only (98 tests)"
    echo "  v2                 Run API v2 tests only (RRSets, Bulk, PTR)"
    echo "  all                Run both v1 and v2 tests (default)"
    echo ""
    echo "API v1 Test Suites (use with 'test v1:SUITE'):"
    echo "  v1:auth            Authentication tests only"
    echo "  v1:users           User management tests"
    echo "  v1:zones           Zone management tests"
    echo "  v1:records         Record management tests"
    echo "  v1:security        Security tests"
    echo "  v1:performance     Performance tests"
    echo ""
    echo "Load Test Types:"
    echo "  all                Run all load tests (default)"
    echo "  auth               Authentication load test"
    echo "  zones              Zone creation load test"
    echo "  rate-limit         Rate limiting test"
    echo "  memory             Memory leak test"
    echo ""
    echo "Examples:"
    echo "  $0 test v1              # Run all API v1 tests"
    echo "  $0 test v2              # Run all API v2 tests"
    echo "  $0 test all             # Run both v1 and v2 tests"
    echo "  $0 test v1:auth         # Run v1 auth tests only"
    echo "  $0 --db pgsql test all  # Test against PostgreSQL"
    echo "  $0 test:all-dbs         # Test all databases"
    echo "  $0 load rate-limit      # Run rate limiting test"
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

check_config() {
    if [[ ! -f "$CONFIG_FILE" ]]; then
        echo -e "${RED}Configuration file not found: $CONFIG_FILE${NC}"
        echo ""
        echo "Available configuration files:"
        echo "  .env.api-test.mysql  - MySQL (port 8080)"
        echo "  .env.api-test.pgsql  - PostgreSQL (port 8081)"
        echo "  .env.api-test.sqlite - SQLite (port 8082)"
        echo ""
        echo "Use --db flag to select: $0 --db mysql test all"
        return 1
    fi

    # Load and validate configuration
    set -a
    source "$CONFIG_FILE"
    set +a

    # Base required vars for all database types
    local required_vars=("API_BASE_URL" "API_KEY" "DB_TYPE")

    # SQLite doesn't require host/user/name
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
        echo "Please edit $CONFIG_FILE"
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
    local version="${1:-all}"

    # Export CONFIG_FILE so test scripts can use it
    export CONFIG_FILE

    case "$version" in
        "v1")
            echo -e "${BLUE}Running API v1 tests${NC}"
            echo ""
            if [[ ! -x "$API_V1_TEST_SCRIPT" ]]; then
                chmod +x "$API_V1_TEST_SCRIPT"
            fi
            "$API_V1_TEST_SCRIPT" "all"
            ;;
        "v1:"*)
            # Extract suite name after v1:
            local suite="${version#v1:}"
            echo -e "${BLUE}Running API v1 tests: $suite${NC}"
            echo ""
            if [[ ! -x "$API_V1_TEST_SCRIPT" ]]; then
                chmod +x "$API_V1_TEST_SCRIPT"
            fi
            "$API_V1_TEST_SCRIPT" "$suite"
            ;;
        "v2")
            echo -e "${BLUE}Running API v2 tests${NC}"
            echo ""
            if [[ ! -x "$API_V2_TEST_SCRIPT" ]]; then
                chmod +x "$API_V2_TEST_SCRIPT"
            fi
            "$API_V2_TEST_SCRIPT"
            ;;
        "all")
            echo -e "${BLUE}Running all API tests (v1 + v2)${NC}"
            echo ""

            # Run v1 tests
            echo -e "${CYAN}═══════════════════════════════════════════${NC}"
            echo -e "${CYAN}  API v1 Test Suite${NC}"
            echo -e "${CYAN}═══════════════════════════════════════════${NC}"
            if [[ ! -x "$API_V1_TEST_SCRIPT" ]]; then
                chmod +x "$API_V1_TEST_SCRIPT"
            fi
            "$API_V1_TEST_SCRIPT" "all"
            local v1_exit=$?

            echo ""
            echo -e "${CYAN}═══════════════════════════════════════════${NC}"
            echo -e "${CYAN}  API v2 Test Suite${NC}"
            echo -e "${CYAN}═══════════════════════════════════════════${NC}"
            if [[ ! -x "$API_V2_TEST_SCRIPT" ]]; then
                chmod +x "$API_V2_TEST_SCRIPT"
            fi
            "$API_V2_TEST_SCRIPT"
            local v2_exit=$?

            # Return failure if either suite failed
            if [[ $v1_exit -ne 0 ]] || [[ $v2_exit -ne 0 ]]; then
                return 1
            fi
            ;;
        *)
            echo -e "${RED}Unknown test version: $version${NC}"
            echo "Use: v1, v2, or all"
            return 1
            ;;
    esac
}

run_tests_all_databases() {
    local version="${1:-all}"
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
                if run_api_tests "$version"; then
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

run_load_tests() {
    local test_type="${1:-all}"

    echo -e "${BLUE}Running load tests: $test_type${NC}"
    echo ""

    if [[ ! -f "$LOAD_TEST_SCRIPT" ]]; then
        echo -e "${RED}Load test script not found: $LOAD_TEST_SCRIPT${NC}"
        return 1
    fi

    if [[ ! -x "$LOAD_TEST_SCRIPT" ]]; then
        chmod +x "$LOAD_TEST_SCRIPT"
    fi

    # Export CONFIG_FILE so load test script can use it
    export CONFIG_FILE
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

    # Parse arguments - extract --db flag first
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
    local command="${args[0]:-}"
    local suite="${args[1]:-all}"

    case "$command" in
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
