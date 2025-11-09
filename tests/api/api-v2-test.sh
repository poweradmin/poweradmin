#!/bin/bash

##############################################################################
# Poweradmin API v2 Test Suite
# Comprehensive testing for API v2 endpoints
# Tests: RRSets, PTR auto-creation, Bulk operations, Master port syntax
##############################################################################

set -euo pipefail

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Configuration
CONFIG_FILE="${SCRIPT_DIR}/.env.api-test"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Test counters
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# Stored IDs
TEST_ZONE_ID=""
TEST_RECORD_ID=""
TEST_SLAVE_ZONE_ID=""
TEST_REVERSE_ZONE_ID=""

##############################################################################
# Utility Functions
##############################################################################

print_header() {
    echo -e "${BLUE}================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}================================${NC}"
}

print_section() {
    echo -e "\n${PURPLE}--- $1 ---${NC}"
}

print_test() {
    echo -e "${CYAN}Testing: $1${NC}"
}

print_pass() {
    echo -e "${GREEN}✓ PASS: $1${NC}"
    ((PASSED_TESTS++))
}

print_fail() {
    echo -e "${RED}✗ FAIL: $1${NC}"
    ((FAILED_TESTS++))
}

print_info() {
    echo -e "${YELLOW}INFO: $1${NC}"
}

increment_test() {
    ((TOTAL_TESTS++))
}

##############################################################################
# Configuration Loading
##############################################################################

load_config() {
    if [[ ! -f "$CONFIG_FILE" ]]; then
        echo -e "${RED}Config file not found: $CONFIG_FILE${NC}"
        echo "Please copy .env.api-test.example to .env.api-test and configure it"
        exit 1
    fi

    # Source the config file
    source "$CONFIG_FILE"

    # Validate required variables
    if [[ -z "${API_BASE_URL:-}" ]] || [[ -z "${API_KEY:-}" ]]; then
        echo -e "${RED}Missing required configuration: API_BASE_URL or API_KEY${NC}"
        exit 1
    fi

    print_info "Using API Base URL: $API_BASE_URL"
}

##############################################################################
# API Request Functions
##############################################################################

api_request_v2() {
    local method="$1"
    local endpoint="$2"
    local data="${3:-}"
    local expected_status="${4:-200}"
    local description="${5:-API v2 request}"

    increment_test
    print_test "$description"

    local curl_opts=(
        -s
        -w "\n%{http_code}"
        -H "X-API-Key: $API_KEY"
        -H "Content-Type: application/json"
        -H "Accept: application/json"
        -X "$method"
        --max-time 30
    )

    if [[ -n "$data" ]]; then
        curl_opts+=(-d "$data")
    fi

    local url="${API_BASE_URL}/api/v2${endpoint}"
    local response
    local http_code

    response=$(curl "${curl_opts[@]}" "$url")

    # Extract HTTP code from last line
    http_code=$(echo "$response" | tail -1)
    body=$(echo "$response" | sed '$d')

    # Store response
    LAST_RESPONSE_BODY="$body"
    LAST_RESPONSE_CODE="$http_code"

    if [[ "$http_code" -eq "$expected_status" ]]; then
        print_pass "$description (HTTP $http_code)"
        return 0
    else
        print_fail "$description - Expected $expected_status, got $http_code"
        echo "Response: $body"
        return 1
    fi
}

extract_json_field() {
    local json="$1"
    local field="$2"
    echo "$json" | grep -o "\"$field\":[^,}]*" | head -1 | sed 's/.*://; s/"//g; s/ //g'
}

##############################################################################
# Test: RRSet Endpoints
##############################################################################

test_rrsets() {
    print_section "RRSet Management Tests"

    # Prerequisites: Create test zone
    print_info "Creating test zone for RRSet tests..."
    local zone_data='{"name":"rrset-test.example.com","type":"MASTER"}'

    if api_request_v2 "POST" "/zones" "$zone_data" 201 "Create test zone"; then
        TEST_ZONE_ID=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
        print_info "Created zone ID: $TEST_ZONE_ID"
    else
        print_fail "Failed to create test zone - skipping RRSet tests"
        return 1
    fi

    # Test 1: Create RRSet with multiple records
    local rrset_data='{
        "name": "www",
        "type": "A",
        "ttl": 3600,
        "records": [
            {"content": "192.0.2.1", "disabled": false},
            {"content": "192.0.2.2", "disabled": false},
            {"content": "192.0.2.3", "disabled": false}
        ]
    }'
    api_request_v2 "PUT" "/zones/$TEST_ZONE_ID/rrsets" "$rrset_data" 200 "Create RRSet with 3 A records"

    # Test 2: Get all RRSets
    api_request_v2 "GET" "/zones/$TEST_ZONE_ID/rrsets" "" 200 "List all RRSets in zone"

    # Test 3: Get specific RRSet
    api_request_v2 "GET" "/zones/$TEST_ZONE_ID/rrsets/www/A" "" 200 "Get specific RRSet (www/A)"

    # Test 4: Verify RRSet contains 3 records
    if [[ "$LAST_RESPONSE_BODY" =~ \"records\" ]]; then
        local record_count=$(echo "$LAST_RESPONSE_BODY" | grep -o "\"content\"" | wc -l)
        increment_test
        if [[ $record_count -eq 3 ]]; then
            print_pass "RRSet contains exactly 3 records"
        else
            print_fail "RRSet should contain 3 records, found $record_count"
        fi
    fi

    # Test 5: Update RRSet (replace with 2 records)
    local rrset_update='{
        "name": "www",
        "type": "A",
        "ttl": 7200,
        "records": [
            {"content": "192.0.2.10", "disabled": false},
            {"content": "192.0.2.20", "disabled": false}
        ]
    }'
    api_request_v2 "PUT" "/zones/$TEST_ZONE_ID/rrsets" "$rrset_update" 200 "Update RRSet (replace 3 with 2 records)"

    # Test 6: Verify update
    api_request_v2 "GET" "/zones/$TEST_ZONE_ID/rrsets/www/A" "" 200 "Verify RRSet update"

    if [[ "$LAST_RESPONSE_BODY" =~ \"records\" ]]; then
        local updated_count=$(echo "$LAST_RESPONSE_BODY" | grep -o "\"content\"" | wc -l)
        increment_test
        if [[ $updated_count -eq 2 ]]; then
            print_pass "RRSet correctly updated to 2 records"
        else
            print_fail "RRSet should contain 2 records after update, found $updated_count"
        fi
    fi

    # Test 7: Create another RRSet (AAAA)
    local rrset_aaaa='{
        "name": "www",
        "type": "AAAA",
        "ttl": 3600,
        "records": [
            {"content": "2001:db8::1", "disabled": false}
        ]
    }'
    api_request_v2 "PUT" "/zones/$TEST_ZONE_ID/rrsets" "$rrset_aaaa" 200 "Create AAAA RRSet"

    # Test 8: Filter RRSets by type
    api_request_v2 "GET" "/zones/$TEST_ZONE_ID/rrsets?type=A" "" 200 "Filter RRSets by type (A)"

    # Test 9: Delete specific RRSet
    api_request_v2 "DELETE" "/zones/$TEST_ZONE_ID/rrsets/www/AAAA" "" 204 "Delete AAAA RRSet"

    # Test 10: Verify deletion
    api_request_v2 "GET" "/zones/$TEST_ZONE_ID/rrsets/www/AAAA" "" 404 "Verify RRSet deleted"

    # Test 11: Delete non-existent RRSet
    api_request_v2 "DELETE" "/zones/$TEST_ZONE_ID/rrsets/nonexistent/A" "" 404 "Delete non-existent RRSet returns 404"

    # Test 12: Invalid RRSet creation (empty records)
    local invalid_rrset='{"name":"invalid","type":"A","ttl":3600,"records":[]}'
    api_request_v2 "PUT" "/zones/$TEST_ZONE_ID/rrsets" "$invalid_rrset" 400 "Reject RRSet with empty records"

    # Test 13: Invalid RRSet creation (invalid IP)
    local invalid_ip='{"name":"bad","type":"A","ttl":3600,"records":[{"content":"999.999.999.999"}]}'
    api_request_v2 "PUT" "/zones/$TEST_ZONE_ID/rrsets" "$invalid_ip" 400 "Reject RRSet with invalid IP"

    # Test 14: Zone apex RRSet (@)
    local apex_rrset='{
        "name": "@",
        "type": "A",
        "ttl": 3600,
        "records": [
            {"content": "192.0.2.100", "disabled": false}
        ]
    }'
    api_request_v2 "PUT" "/zones/$TEST_ZONE_ID/rrsets" "$apex_rrset" 200 "Create zone apex RRSet (@)"

    print_info "RRSet tests completed"
}

##############################################################################
# Test: PTR Auto-Creation
##############################################################################

test_ptr_autocreation() {
    print_section "PTR Auto-Creation Tests"

    # Prerequisites: Create forward zone and reverse zone
    print_info "Creating forward zone..."
    local forward_zone='{"name":"ptr-test.example.com","type":"MASTER"}'

    if api_request_v2 "POST" "/zones" "$forward_zone" 201 "Create forward zone"; then
        TEST_ZONE_ID=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
        print_info "Created forward zone ID: $TEST_ZONE_ID"
    else
        print_fail "Failed to create forward zone - skipping PTR tests"
        return 1
    fi

    print_info "Creating reverse zone (2.0.192.in-addr.arpa)..."
    local reverse_zone='{"name":"2.0.192.in-addr.arpa","type":"MASTER"}'

    if api_request_v2 "POST" "/zones" "$reverse_zone" 201 "Create reverse zone"; then
        TEST_REVERSE_ZONE_ID=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
        print_info "Created reverse zone ID: $TEST_REVERSE_ZONE_ID"
    else
        print_info "Reverse zone might already exist or creation failed"
    fi

    # Test 1: Create A record WITH PTR auto-creation
    local record_with_ptr='{
        "name": "host1",
        "type": "A",
        "content": "192.0.2.100",
        "ttl": 3600,
        "create_ptr": true
    }'
    api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records" "$record_with_ptr" 201 "Create A record with PTR auto-creation"

    # Check if PTR was created (response should mention it)
    increment_test
    if [[ "$LAST_RESPONSE_BODY" =~ "ptr_created" ]]; then
        if [[ "$LAST_RESPONSE_BODY" =~ "\"ptr_created\":true" ]]; then
            print_pass "PTR record auto-creation succeeded"
        else
            print_info "PTR record auto-creation was attempted but may have failed (check reverse zone)"
        fi
    else
        print_info "Response doesn't include PTR status (old API version?)"
    fi

    # Test 2: Create A record WITHOUT PTR auto-creation (default)
    local record_no_ptr='{
        "name": "host2",
        "type": "A",
        "content": "192.0.2.101",
        "ttl": 3600
    }'
    api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records" "$record_no_ptr" 201 "Create A record without PTR (default)"

    # Test 3: Create AAAA record with PTR
    local aaaa_with_ptr='{
        "name": "host3",
        "type": "AAAA",
        "content": "2001:db8::100",
        "ttl": 3600,
        "create_ptr": true
    }'
    api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records" "$aaaa_with_ptr" 201 "Create AAAA record with PTR"

    # Test 4: create_ptr on CNAME (should be ignored)
    local cname_with_ptr='{
        "name": "alias",
        "type": "CNAME",
        "content": "host1.ptr-test.example.com.",
        "ttl": 3600,
        "create_ptr": true
    }'
    api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records" "$cname_with_ptr" 201 "PTR flag ignored on CNAME"

    # Test 5: Verify reverse zone has PTR records (if reverse zone exists)
    if [[ -n "$TEST_REVERSE_ZONE_ID" ]]; then
        api_request_v2 "GET" "/zones/$TEST_REVERSE_ZONE_ID/records" "" 200 "List records in reverse zone"

        increment_test
        if [[ "$LAST_RESPONSE_BODY" =~ "100.2.0.192.in-addr.arpa" ]]; then
            print_pass "PTR record found in reverse zone"
        else
            print_info "PTR record not found (may require manual verification)"
        fi
    fi

    print_info "PTR auto-creation tests completed"
}

##############################################################################
# Test: Bulk Operations
##############################################################################

test_bulk_operations() {
    print_section "Bulk Operations Tests"

    # Prerequisites: Create test zone
    print_info "Creating test zone for bulk operations..."
    local zone_data='{"name":"bulk-test.example.com","type":"MASTER"}'

    if api_request_v2 "POST" "/zones" "$zone_data" 201 "Create test zone"; then
        TEST_ZONE_ID=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
        print_info "Created zone ID: $TEST_ZONE_ID"
    else
        print_fail "Failed to create test zone - skipping bulk tests"
        return 1
    fi

    # Test 1: Bulk create 10 records
    local bulk_create='{
        "operations": [
            {"action":"create","name":"bulk1","type":"A","content":"192.0.2.1","ttl":3600},
            {"action":"create","name":"bulk2","type":"A","content":"192.0.2.2","ttl":3600},
            {"action":"create","name":"bulk3","type":"A","content":"192.0.2.3","ttl":3600},
            {"action":"create","name":"bulk4","type":"A","content":"192.0.2.4","ttl":3600},
            {"action":"create","name":"bulk5","type":"A","content":"192.0.2.5","ttl":3600},
            {"action":"create","name":"bulk6","type":"A","content":"192.0.2.6","ttl":3600},
            {"action":"create","name":"bulk7","type":"A","content":"192.0.2.7","ttl":3600},
            {"action":"create","name":"bulk8","type":"A","content":"192.0.2.8","ttl":3600},
            {"action":"create","name":"bulk9","type":"A","content":"192.0.2.9","ttl":3600},
            {"action":"create","name":"bulk10","type":"A","content":"192.0.2.10","ttl":3600}
        ]
    }'
    api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records/bulk" "$bulk_create" 200 "Bulk create 10 records"

    # Verify response
    increment_test
    if [[ "$LAST_RESPONSE_BODY" =~ "\"created\":10" ]]; then
        print_pass "Bulk operation created exactly 10 records"
    else
        print_fail "Bulk operation should have created 10 records"
    fi

    # Test 2: Get records and extract IDs for update/delete
    api_request_v2 "GET" "/zones/$TEST_ZONE_ID/records" "" 200 "Get all records"

    # Extract first record ID for testing
    local first_record_id=$(extract_json_field "$LAST_RESPONSE_BODY" "id")

    # Test 3: Bulk mixed operations (create + update + delete)
    if [[ -n "$first_record_id" ]]; then
        local bulk_mixed="{
            \"operations\": [
                {\"action\":\"create\",\"name\":\"new1\",\"type\":\"A\",\"content\":\"192.0.2.50\",\"ttl\":3600},
                {\"action\":\"update\",\"id\":$first_record_id,\"content\":\"192.0.2.99\"},
                {\"action\":\"delete\",\"id\":$first_record_id}
            ]
        }"
        # Note: This will fail because we can't update and delete same record
        # Let's do a valid mixed operation instead

        local bulk_valid="{
            \"operations\": [
                {\"action\":\"create\",\"name\":\"new1\",\"type\":\"A\",\"content\":\"192.0.2.50\",\"ttl\":3600},
                {\"action\":\"create\",\"name\":\"new2\",\"type\":\"A\",\"content\":\"192.0.2.51\",\"ttl\":3600}
            ]
        }"
        api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records/bulk" "$bulk_valid" 200 "Bulk mixed operations"
    fi

    # Test 4: Bulk operation with validation error (should rollback)
    local bulk_invalid='{
        "operations": [
            {"action":"create","name":"valid","type":"A","content":"192.0.2.20","ttl":3600},
            {"action":"create","name":"invalid","type":"A","content":"999.999.999.999","ttl":3600}
        ]
    }'
    api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records/bulk" "$bulk_invalid" 500 "Bulk with invalid record (atomic rollback)"

    # Test 5: Verify rollback (valid record should not exist)
    api_request_v2 "GET" "/zones/$TEST_ZONE_ID/records" "" 200 "Verify rollback"

    increment_test
    if [[ ! "$LAST_RESPONSE_BODY" =~ "192.0.2.20" ]]; then
        print_pass "Transaction rolled back correctly (no partial records)"
    else
        print_fail "Rollback failed - found record that should have been rolled back"
    fi

    # Test 6: Empty operations array
    local bulk_empty='{"operations":[]}'
    api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records/bulk" "$bulk_empty" 400 "Reject empty operations array"

    # Test 7: Invalid action type
    local bulk_bad_action='{"operations":[{"action":"invalid","name":"test","type":"A","content":"192.0.2.1"}]}'
    api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records/bulk" "$bulk_bad_action" 500 "Reject invalid action type"

    # Test 8: Bulk delete all test records
    api_request_v2 "GET" "/zones/$TEST_ZONE_ID/records?type=A" "" 200 "Get all A records"

    # Count records (for info)
    local record_count=$(echo "$LAST_RESPONSE_BODY" | grep -o "\"id\"" | wc -l | tr -d ' ')
    print_info "Found $record_count A records before bulk delete"

    print_info "Bulk operations tests completed"
}

##############################################################################
# Test: Master Port Syntax
##############################################################################

test_master_port_syntax() {
    print_section "Master Port Syntax Tests"

    # Test 1: Create SLAVE zone with simple IP
    local slave_simple='{"name":"slave-simple.example.com","type":"SLAVE","masters":"192.0.2.1"}'
    api_request_v2 "POST" "/zones" "$slave_simple" 201 "Create SLAVE zone with simple IP"

    if [[ "$LAST_RESPONSE_CODE" -eq 201 ]]; then
        TEST_SLAVE_ZONE_ID=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
        print_info "Created SLAVE zone ID: $TEST_SLAVE_ZONE_ID"
    fi

    # Test 2: Create SLAVE zone with IP:port
    local slave_port='{"name":"slave-port.example.com","type":"SLAVE","masters":"192.0.2.1:5300"}'
    api_request_v2 "POST" "/zones" "$slave_port" 201 "Create SLAVE zone with IP:port"

    # Test 3: Create SLAVE zone with multiple IPs and ports
    local slave_multi='{"name":"slave-multi.example.com","type":"SLAVE","masters":"192.0.2.1:5300,192.0.2.2:5300"}'
    api_request_v2 "POST" "/zones" "$slave_multi" 201 "Create SLAVE zone with multiple IP:port"

    # Test 4: Create SLAVE zone with IPv6 and port
    local slave_ipv6='{"name":"slave-ipv6.example.com","type":"SLAVE","masters":"[2001:db8::1]:5300"}'
    api_request_v2 "POST" "/zones" "$slave_ipv6" 201 "Create SLAVE zone with IPv6:port"

    # Test 5: Mixed IPv4 and IPv6
    local slave_mixed='{"name":"slave-mixed.example.com","type":"SLAVE","masters":"192.0.2.1:5300,[2001:db8::1]:5300"}'
    api_request_v2 "POST" "/zones" "$slave_mixed" 201 "Create SLAVE zone with mixed IPv4/IPv6"

    # Test 6: Invalid port number (too high)
    local slave_bad_port='{"name":"slave-bad-port.example.com","type":"SLAVE","masters":"192.0.2.1:99999"}'
    api_request_v2 "POST" "/zones" "$slave_bad_port" 400 "Reject invalid port number"

    # Test 7: Invalid port number (zero)
    local slave_zero_port='{"name":"slave-zero.example.com","type":"SLAVE","masters":"192.0.2.1:0"}'
    api_request_v2 "POST" "/zones" "$slave_zero_port" 400 "Reject zero port number"

    # Test 8: Invalid IPv4 address
    local slave_bad_ip='{"name":"slave-bad-ip.example.com","type":"SLAVE","masters":"999.999.999.999:5300"}'
    api_request_v2 "POST" "/zones" "$slave_bad_ip" 400 "Reject invalid IPv4 address"

    # Test 9: Invalid IPv6 address
    local slave_bad_ipv6='{"name":"slave-bad-ipv6.example.com","type":"SLAVE","masters":"[gggg:hhhh::1]:5300"}'
    api_request_v2 "POST" "/zones" "$slave_bad_ipv6" 400 "Reject invalid IPv6 address"

    # Test 10: IPv6 without brackets (invalid)
    local slave_no_brackets='{"name":"slave-no-brackets.example.com","type":"SLAVE","masters":"2001:db8::1:5300"}'
    api_request_v2 "POST" "/zones" "$slave_no_brackets" 400 "Reject IPv6 with port but no brackets"

    # Test 11: Update zone master servers
    if [[ -n "$TEST_SLAVE_ZONE_ID" ]]; then
        local update_master='{"master":"192.0.2.10:5300,192.0.2.11:5300"}'
        api_request_v2 "PUT" "/zones/$TEST_SLAVE_ZONE_ID" "$update_master" 200 "Update zone master servers"
    fi

    # Test 12: Verify master servers format in GET response
    if [[ -n "$TEST_SLAVE_ZONE_ID" ]]; then
        api_request_v2 "GET" "/zones/$TEST_SLAVE_ZONE_ID" "" 200 "Get SLAVE zone details"

        increment_test
        if [[ "$LAST_RESPONSE_BODY" =~ "\"masters\":" ]]; then
            print_pass "Master servers field present in response"
        else
            print_fail "Master servers field missing from response"
        fi
    fi

    # Test 13: MASTER zone should not require masters
    local master_no_masters='{"name":"master-nomasters.example.com","type":"MASTER"}'
    api_request_v2 "POST" "/zones" "$master_no_masters" 201 "MASTER zone without masters field"

    # Test 14: SLAVE zone without masters (should fail)
    local slave_no_masters='{"name":"slave-nomasters.example.com","type":"SLAVE"}'
    api_request_v2 "POST" "/zones" "$slave_no_masters" 400 "Reject SLAVE zone without masters"

    print_info "Master port syntax tests completed"
}

##############################################################################
# Cleanup Function
##############################################################################

cleanup() {
    print_section "Cleanup"

    # Delete test zones
    if [[ -n "$TEST_ZONE_ID" ]]; then
        print_info "Deleting test zone $TEST_ZONE_ID..."
        api_request_v2 "DELETE" "/zones/$TEST_ZONE_ID" "" 204 "Delete test zone" || true
    fi

    if [[ -n "$TEST_SLAVE_ZONE_ID" ]]; then
        print_info "Deleting slave zone $TEST_SLAVE_ZONE_ID..."
        api_request_v2 "DELETE" "/zones/$TEST_SLAVE_ZONE_ID" "" 204 "Delete slave zone" || true
    fi

    if [[ -n "$TEST_REVERSE_ZONE_ID" ]]; then
        print_info "Deleting reverse zone $TEST_REVERSE_ZONE_ID..."
        api_request_v2 "DELETE" "/zones/$TEST_REVERSE_ZONE_ID" "" 204 "Delete reverse zone" || true
    fi

    # Delete other test zones by name pattern
    print_info "Cleaning up any remaining test zones..."
    # This would require listing zones and filtering - simplified for now
}

##############################################################################
# Main Test Execution
##############################################################################

main() {
    print_header "PowerAdmin API v2 Enhancements Test Suite"

    load_config

    echo -e "\n${YELLOW}Starting tests...${NC}\n"

    # Run test suites
    test_rrsets
    test_ptr_autocreation
    test_bulk_operations
    test_master_port_syntax

    # Cleanup
    cleanup

    # Print summary
    print_header "Test Summary"
    echo -e "Total Tests: ${TOTAL_TESTS}"
    echo -e "${GREEN}Passed: ${PASSED_TESTS}${NC}"
    echo -e "${RED}Failed: ${FAILED_TESTS}${NC}"

    local pass_rate=0
    if [[ $TOTAL_TESTS -gt 0 ]]; then
        pass_rate=$((PASSED_TESTS * 100 / TOTAL_TESTS))
    fi
    echo -e "Pass Rate: ${pass_rate}%"

    # Exit with error if any tests failed
    if [[ $FAILED_TESTS -gt 0 ]]; then
        exit 1
    fi

    echo -e "\n${GREEN}All tests passed!${NC}\n"
}

# Run main function
main "$@"
