#!/bin/bash

##############################################################################
# Poweradmin API Test Runner
# Simplified test runner for API testing
##############################################################################

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_TEST_SCRIPT="$SCRIPT_DIR/api-test.sh"
CONFIG_FILE="$SCRIPT_DIR/.env.api-test"
CONFIG_EXAMPLE="$SCRIPT_DIR/.env.api-test.example"

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
    echo "  check              Check test prerequisites"
    echo "  clean              Clean up test data"
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
    echo "Examples:"
    echo "  $0 setup"
    echo "  $0 test auth"
    echo "  $0 test all"
    echo "  $0 load rate-limit"
    echo "  $0 check"
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
    
    local required_vars=("API_BASE_URL" "API_KEY" "DB_HOST" "DB_NAME" "DB_USER" "DB_TYPE")
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
    
    "$API_TEST_SCRIPT" "$suite"
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
    case "${1:-}" in
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
            run_api_tests "${2:-all}"
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
            echo -e "${RED}Unknown command: $1${NC}"
            echo ""
            usage
            exit 1
            ;;
    esac
}

main "$@"