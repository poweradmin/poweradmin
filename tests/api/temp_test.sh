#!/bin/bash

# Dynamic DNS API Test Script
# Tests the dynamic_update.php endpoint with various scenarios

set +e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Function to load environment file
load_env_file() {
    local env_file="$1"
    if [[ -f "$env_file" ]]; then
        echo "Loading environment from: $env_file"
        # Read the file line by line
        while IFS= read -r line || [[ -n "$line" ]]; do
            # Skip empty lines and comments
            [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue
            
            # Export the variable if it's in KEY=VALUE format
            if [[ "$line" =~ ^[[:space:]]*([a-zA-Z_][a-zA-Z0-9_]*)=(.*)$ ]]; then
                local key="${BASH_REMATCH[1]}"
                local value="${BASH_REMATCH[2]}"
                
                # Remove surrounding quotes if present
                value="${value#\"}"
                value="${value%\"}"
                value="${value#\'}"
                value="${value%\'}"
                
                # Only set if not already set (allow command line override)
                if [[ -z "${!key}" ]]; then
                    export "$key"="$value"
                fi
            fi
        done < "$env_file"
    fi
}

# Load environment files in order of preference
# 1. .env.api-test (specific to API tests in tests/api/)
# 2. .env.test (general test environment in project root)
# 3. .env.local (local overrides in project root)
# 4. .env (general environment in project root)

load_env_file "$SCRIPT_DIR/.env.api-test"
for env_file in ".env.test" ".env.local" ".env"; do
    load_env_file "$PROJECT_ROOT/$env_file"
done

# Map existing API test variables to our expected variable names (for compatibility)
BASE_URL="${BASE_URL:-${API_BASE_URL:-http://localhost/poweradmin}}"
TEST_USERNAME="${TEST_USERNAME:-${DYNAMIC_DNS_USER:-${HTTP_AUTH_USER:-testuser}}}"
TEST_PASSWORD="${TEST_PASSWORD:-${DYNAMIC_DNS_PASS:-${HTTP_AUTH_PASS:-testpass123}}}"
TEST_HOSTNAME="${TEST_HOSTNAME:-${DYNAMIC_DNS_HOSTNAME:-test.example.com}}"

# Set derived URLs
DYNAMIC_UPDATE_URL="${BASE_URL}/dynamic_update.php"

# Additional configuration options that can be set via environment
CURL_TIMEOUT="${CURL_TIMEOUT:-${TEST_TIMEOUT:-30}}"
SKIP_SSL_VERIFY="${SKIP_SSL_VERIFY:-false}"
TEST_USER_AGENT="${TEST_USER_AGENT:-DynamicDNS API Test Suite/1.0}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counters
TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

# Function to print colored output
print_status() {
    local status=$1
    local message=$2
    case $status in
        "PASS")
            echo -e "${GREEN}[PASS]${NC} $message"
            ((TESTS_PASSED++))
            ;;
        "FAIL")
            echo -e "${RED}[FAIL]${NC} $message"
            ((TESTS_FAILED++))
            ;;
        "INFO")
            echo -e "${YELLOW}[INFO]${NC} $message"
            ;;
        *)
            echo "$message"
            ;;
    esac
    ((TESTS_RUN++))
}

# Function to test HTTP response
test_response() {
    local test_name="$1"
    local expected_response="$2"
    local curl_args="$3"
    
    echo -n "Testing: $test_name... "
    
    # Build curl command with common options
    local curl_cmd="curl -s --max-time $CURL_TIMEOUT -H 'User-Agent: $TEST_USER_AGENT'"
    
    # Add SSL verification skip if requested
    if [[ "$SKIP_SSL_VERIFY" == "true" ]]; then
        curl_cmd="$curl_cmd -k"
    fi
    
    # Add custom curl args and URL
    curl_cmd="$curl_cmd $curl_args '$DYNAMIC_UPDATE_URL'"
    
    local response
    response=$(eval "$curl_cmd")
    local exit_code=$?
    
    if [ $exit_code -ne 0 ]; then
        print_status "FAIL" "$test_name - cURL failed with exit code $exit_code"
        return 1
    fi
    
    if [ "$response" = "$expected_response" ]; then
        print_status "PASS" "$test_name"
        return 0
    else
        print_status "FAIL" "$test_name - Expected '$expected_response', got '$response'"
        return 1
    fi
}

# Function to test HTTP response with basic auth
test_with_auth() {
    local test_name="$1"
    local expected_response="$2"
    local params="$3"
    local username="${4:-$TEST_USERNAME}"
    local password="${5:-$TEST_PASSWORD}"
    
    test_response "$test_name" "$expected_response" "-u '$username:$password' -G $params"
}

# Function to test HTTP response without auth
test_without_auth() {
    local test_name="$1"
    local expected_response="$2"
    local params="$3"
    
    test_response "$test_name" "$expected_response" "-G $params"
}

# Help function
show_help() {
    cat << EOF
Dynamic DNS API Test Suite

Usage: $0 [OPTIONS]

This script tests the dynamic_update.php endpoint with various scenarios.

Environment Configuration:
The script loads configuration from environment files in this order:
1. tests/api/.env.api-test (API test specific)
2. .env.test (general test environment in project root)  
3. .env.local (local overrides in project root)
4. .env (general environment in project root)

You can also set environment variables directly or use command line:
  BASE_URL=https://example.com/poweradmin $0

Supported Environment Variables (Dynamic DNS specific):
  BASE_URL           Poweradmin base URL (default: http://localhost/poweradmin)
  TEST_USERNAME      Test user username (default: testuser)
  TEST_PASSWORD      Test user password (default: testpass123)
  TEST_HOSTNAME      Test hostname to update (default: test.example.com)
  CURL_TIMEOUT       cURL timeout in seconds (default: 30)
  SKIP_SSL_VERIFY    Skip SSL verification (default: false)
  TEST_USER_AGENT    User agent string (default: DynamicDNS API Test Suite/1.0)

Compatibility with existing API test variables:
  API_BASE_URL       Maps to BASE_URL
  DYNAMIC_DNS_USER   Maps to TEST_USERNAME  
  DYNAMIC_DNS_PASS   Maps to TEST_PASSWORD
  DYNAMIC_DNS_HOSTNAME Maps to TEST_HOSTNAME
  HTTP_AUTH_USER     Fallback for TEST_USERNAME
  HTTP_AUTH_PASS     Fallback for TEST_PASSWORD
  TEST_TIMEOUT       Maps to CURL_TIMEOUT

Options:
  -h, --help         Show this help message
  
Setup:
  cp tests/api/.env.api-test.example tests/api/.env.api-test
  # Edit tests/api/.env.api-test with your configuration
  
Example tests/api/.env.api-test file:
  BASE_URL=https://poweradmin.example.com
  TEST_USERNAME=api_test_user
  TEST_PASSWORD=secure_password123
  TEST_HOSTNAME=test.mydomain.com
  SKIP_SSL_VERIFY=true

EOF
}

# Check for help flag
if [[ "$1" == "-h" || "$1" == "--help" ]]; then
    show_help
    exit 0
fi

echo "Dynamic DNS API Test Suite"
echo "=========================="
echo "Configuration:"
echo "  Base URL: $DYNAMIC_UPDATE_URL"
echo "  Test User: $TEST_USERNAME"
echo "  Test Hostname: $TEST_HOSTNAME"
echo "  cURL Timeout: ${CURL_TIMEOUT}s"
echo "  Skip SSL Verify: $SKIP_SSL_VERIFY"
echo "  User Agent: $TEST_USER_AGENT"
echo ""

# Test 1: Missing User-Agent (override the default User-Agent with empty)
print_status "INFO" "Running authentication and basic validation tests..."
test_response "Missing User-Agent" "badagent" "-H 'User-Agent:' -u '$TEST_USERNAME:$TEST_PASSWORD' -G -d 'hostname=$TEST_HOSTNAME&myip=192.168.1.1'"

# Test 2: Missing Authentication
test_without_auth "Missing Authentication" "badauth" "-d 'hostname=$TEST_HOSTNAME&myip=192.168.1.1'"

# Test 3: Invalid Credentials
test_with_auth "Invalid Credentials" "badauth2" "-d 'hostname=$TEST_HOSTNAME&myip=192.168.1.1'" "$TEST_USERNAME" "wrongpassword"

# Test 4: Missing Hostname
test_with_auth "Missing Hostname" "notfqdn" "-d 'myip=192.168.1.1'"

# Test 5: Empty Hostname
test_with_auth "Empty Hostname" "notfqdn" "-d 'hostname=&myip=192.168.1.1'"

# Test 6: Invalid IP Address
test_with_auth "Invalid IP Address" "dnserr" "-d 'hostname=$TEST_HOSTNAME&myip=invalid.ip'"

# Test 7: No IP Addresses Provided
test_with_auth "No IP Addresses" "dnserr" "-d 'hostname=$TEST_HOSTNAME'"

print_status "INFO" "Running IPv4 update tests..."

# Test 8: Valid IPv4 Update
test_with_auth "Valid IPv4 Update" "good" "-d 'hostname=$TEST_HOSTNAME&myip=192.168.1.100'"

# Test 9: Multiple IPv4 Addresses
test_with_auth "Multiple IPv4 Addresses" "good" "-d 'hostname=$TEST_HOSTNAME&myip=192.168.1.101,192.168.1.102'"

# Test 10: IPv4 with Spaces
test_with_auth "IPv4 with Spaces" "good" "-d 'hostname=$TEST_HOSTNAME&myip= 192.168.1.103 , 192.168.1.104 '"

print_status "INFO" "Running IPv6 update tests..."

# Test 11: Valid IPv6 Update
test_with_auth "Valid IPv6 Update" "good" "-d 'hostname=$TEST_HOSTNAME&myip6=2001:db8::1'"

# Test 12: Multiple IPv6 Addresses
test_with_auth "Multiple IPv6 Addresses" "good" "-d 'hostname=$TEST_HOSTNAME&myip6=2001:db8::2,2001:db8::3'"

# Test 13: IPv6 Loopback
test_with_auth "IPv6 Loopback" "good" "-d 'hostname=$TEST_HOSTNAME&myip6=::1'"

print_status "INFO" "Running dual-stack tests..."

# Test 14: Dual-stack Update
test_with_auth "Dual-stack Update" "good" "-d 'hostname=$TEST_HOSTNAME&myip=192.168.1.105&myip6=2001:db8::4&dualstack_update=1'"

# Test 15: Mixed Valid and Invalid IPs (IPv4)
test_with_auth "Mixed Valid/Invalid IPv4" "good" "-d 'hostname=$TEST_HOSTNAME&myip=192.168.1.106,invalid.ip,192.168.1.107'"

# Test 16: Mixed Valid and Invalid IPs (IPv6)
test_with_auth "Mixed Valid/Invalid IPv6" "good" "-d 'hostname=$TEST_HOSTNAME&myip6=2001:db8::5,invalid::ip,2001:db8::6'"

print_status "INFO" "Running special parameter tests..."

# Test 17: whatismyip Parameter (Note: This will use the server's perceived client IP)
test_with_auth "whatismyip Parameter" "good" "-d 'hostname=$TEST_HOSTNAME&myip=whatismyip'"

# Test 18: Alternative Parameter Names (ip instead of myip)
test_with_auth "Alternative Parameter ip" "good" "-d 'hostname=$TEST_HOSTNAME&ip=192.168.1.108'"

# Test 19: Alternative Parameter Names (ip6 instead of myip6)
test_with_auth "Alternative Parameter ip6" "good" "-d 'hostname=$TEST_HOSTNAME&ip6=2001:db8::7'"

print_status "INFO" "Running query string authentication tests..."

# Test 20: Query String Authentication
test_without_auth "Query String Auth" "good" "-d 'hostname=$TEST_HOSTNAME&myip=192.168.1.109&username=$TEST_USERNAME&password=$TEST_PASSWORD'"

print_status "INFO" "Running verbose output tests..."

# Test 21: Verbose Output - Success
test_with_auth "Verbose Success" "Your hostname has been updated." "-d 'hostname=$TEST_HOSTNAME&myip=192.168.1.110&verbose=1'"

# Test 22: Verbose Output - Authentication Error
test_with_auth "Verbose Auth Error" "Invalid username or password.  Authentication failed." "-d 'hostname=$TEST_HOSTNAME&myip=192.168.1.111&verbose=1'" "$TEST_USERNAME" "wrongpassword"

# Test 23: Verbose Output - Invalid Hostname
test_with_auth "Verbose Invalid Hostname" "The hostname you specified was not valid." "-d 'hostname=&myip=192.168.1.112&verbose=1'"

print_status "INFO" "Running edge case tests..."

# Test 24: Very Long IP List (testing system limits)
LONG_IP_LIST=""
for i in {1..10}; do
    if [ -n "$LONG_IP_LIST" ]; then
        LONG_IP_LIST="${LONG_IP_LIST},"
    fi
    LONG_IP_LIST="${LONG_IP_LIST}192.168.2.$i"
done
test_with_auth "Long IP List" "good" "-d 'hostname=$TEST_HOSTNAME&myip=$LONG_IP_LIST'"

# Test 25: Empty IP in List
test_with_auth "Empty IP in List" "good" "-d 'hostname=$TEST_HOSTNAME&myip=192.168.1.113,,192.168.1.114'"

# Test 26: Only Commas
test_with_auth "Only Commas" "dnserr" "-d 'hostname=$TEST_HOSTNAME&myip=,,,'"

# Test 27: POST Method
test_with_auth "POST Method" "good" "-X POST -d 'hostname=$TEST_HOSTNAME&myip=192.168.1.115'"

print_status "INFO" "Running security tests..."

# Test 28: SQL Injection Attempt in Hostname
test_with_auth "SQL Injection Hostname" "!yours" "-G --data-urlencode 'hostname=test.example.com'; DROP TABLE users; --' -d 'myip=192.168.1.116'"

# Test 29: XSS Attempt in Hostname
test_with_auth "XSS Attempt Hostname" "!yours" "-G --data-urlencode 'hostname=<script>alert(1)</script>' -d 'myip=192.168.1.117'"

# Test 30: Very Long Hostname
LONG_HOSTNAME=$(printf 'a%.0s' {1..300})
test_with_auth "Very Long Hostname" "!yours" "-G --data-urlencode 'hostname=$LONG_HOSTNAME.example.com' -d 'myip=192.168.1.118'"

echo ""
echo "Test Summary"
echo "============"
echo "Tests run: $TESTS_RUN"
echo "Tests passed: $TESTS_PASSED"
echo "Tests failed: $TESTS_FAILED"

if [ $TESTS_FAILED -eq 0 ]; then
    print_status "PASS" "All tests passed!"
    exit 0
else
    print_status "FAIL" "$TESTS_FAILED test(s) failed"
    exit 1
fi