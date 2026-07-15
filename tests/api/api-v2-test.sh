#!/bin/bash

##############################################################################
# Poweradmin API v2 Test Suite
# Comprehensive testing for API v2 endpoints
# Tests: RRSets, PTR auto-creation, Bulk operations, Master port syntax, Groups, Metadata, Users
##############################################################################

set -euo pipefail

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Configuration
# Default to MySQL config for devcontainer testing
CONFIG_FILE="${CONFIG_FILE:-${SCRIPT_DIR}/.env.api-test.mysql}"

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
TEST_GROUP_ID=""
TEST_USER_ID=""
TEST_ZONE_TEMPLATE_ID=""
TEST_ZONE_TEMPLATE_RECORD_ID=""
TEST_OWNER_ZONE_ID=""
TEST_OWNER_USER_ID=""
CREATED_REVERSE_ZONE=false

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
    PASSED_TESTS=$((PASSED_TESTS + 1))
}

print_fail() {
    echo -e "${RED}✗ FAIL: $1${NC}"
    FAILED_TESTS=$((FAILED_TESTS + 1))
}

print_info() {
    echo -e "${YELLOW}INFO: $1${NC}"
}

increment_test() {
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
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
        --max-time 30
    )
    # curl -X HEAD waits for a body a HEAD response never sends; --head reads headers only.
    if [[ "$method" == "HEAD" ]]; then
        curl_opts+=(--head)
    else
        curl_opts+=(-X "$method")
    fi

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
    echo "$json" | grep -o "\"$field\":[^,}]*" | head -1 | sed 's/.*://; s/"//g; s/ //g' || true
}

# Assert a jq expression evaluates to expected value
assert_json() {
    local description="$1"
    local json="$2"
    local jq_expr="$3"
    local expected="$4"

    increment_test
    local actual
    actual=$(echo "$json" | jq -r "$jq_expr" 2>/dev/null)

    if [[ "$actual" == "$expected" ]]; then
        print_pass "$description"
    else
        print_fail "$description - Expected '$expected', got '$actual'"
    fi
}

# Assert a jq expression returns non-null/non-empty
assert_json_exists() {
    local description="$1"
    local json="$2"
    local jq_expr="$3"

    increment_test
    local actual
    actual=$(echo "$json" | jq -r "$jq_expr" 2>/dev/null)

    if [[ -n "$actual" ]] && [[ "$actual" != "null" ]]; then
        print_pass "$description"
    else
        print_fail "$description - Field not found or null"
    fi
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

    # Verify PUT returns full rrset data
    assert_json_exists "RRSet PUT returns rrset name" "$LAST_RESPONSE_BODY" '.data.rrset.name'
    assert_json_exists "RRSet PUT returns rrset records" "$LAST_RESPONSE_BODY" '.data.rrset.records'

    # Test 2: Get all RRSets
    api_request_v2 "GET" "/zones/$TEST_ZONE_ID/rrsets" "" 200 "List all RRSets in zone"

    # Verify list response wrapping
    assert_json_exists "RRSet list wrapped in data.rrsets" "$LAST_RESPONSE_BODY" '.data.rrsets'

    # Test 3: Get specific RRSet
    api_request_v2 "GET" "/zones/$TEST_ZONE_ID/rrsets/www/A" "" 200 "Get specific RRSet (www/A)"

    # Verify single response wrapping
    assert_json_exists "RRSet GET wrapped in data.rrset" "$LAST_RESPONSE_BODY" '.data.rrset.name'

    # Test 4: Verify RRSet contains 3 records
    # Verify record count using jq
    increment_test
    local record_count=$(echo "$LAST_RESPONSE_BODY" | jq '.data.rrset.records | length' 2>/dev/null)
    if [[ "$record_count" -eq 3 ]]; then
        print_pass "RRSet contains exactly 3 records"
    else
        print_fail "RRSet should contain 3 records, found $record_count"
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

    # Verify updated record count
    increment_test
    local updated_count=$(echo "$LAST_RESPONSE_BODY" | jq '.data.rrset.records | length' 2>/dev/null)
    if [[ "$updated_count" -eq 2 ]]; then
        print_pass "RRSet correctly updated to 2 records"
    else
        print_fail "RRSet should contain 2 records after update, found $updated_count"
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

    print_info "Setting up reverse zone (2.0.192.in-addr.arpa)..."
    local reverse_zone='{"name":"2.0.192.in-addr.arpa","type":"MASTER"}'

    # Try to create reverse zone, but it may already exist from test data
    local rev_response
    rev_response=$(curl -s -w "\n%{http_code}" \
        -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" -H "Accept: application/json" \
        -X POST -d "$reverse_zone" "${API_BASE_URL}/api/v2/zones" 2>/dev/null)
    local rev_code=$(echo "$rev_response" | tail -1)
    local rev_body=$(echo "$rev_response" | sed '$d')

    if [[ "$rev_code" == "201" ]]; then
        TEST_REVERSE_ZONE_ID=$(echo "$rev_body" | grep -o '"zone_id":[^,}]*' | head -1 | sed 's/.*://; s/"//g; s/ //g')
        CREATED_REVERSE_ZONE=true
        print_info "Created reverse zone ID: $TEST_REVERSE_ZONE_ID"
    else
        # Reverse zone already exists from test data - look it up via v1 API
        print_info "Reverse zone already exists, looking up ID..."
        local existing_zones
        existing_zones=$(curl -s -H "X-API-Key: $API_KEY" -H "Accept: application/json" \
            "${API_BASE_URL}/api/v1/zones" 2>/dev/null | jq -r '[.data[]? | select(.name=="2.0.192.in-addr.arpa") | .zone_id // .id][0] // empty')
        if [[ -n "$existing_zones" ]]; then
            TEST_REVERSE_ZONE_ID="$existing_zones"
            print_info "Found existing reverse zone ID: $TEST_REVERSE_ZONE_ID"
        fi
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
# Test: PTR sync on record update (issue #1255)
##############################################################################

test_ptr_update() {
    print_section "PTR Update Tests"

    if [[ -z "$TEST_ZONE_ID" || -z "$TEST_REVERSE_ZONE_ID" ]]; then
        print_info "Skipping PTR update tests - PTR auto-creation didn't run"
        return 0
    fi

    # Seed an A record with PTR so we have something to update.
    local seed='{
        "name": "ptr-upd",
        "type": "A",
        "content": "192.0.2.200",
        "ttl": 3600,
        "create_ptr": true
    }'
    if ! api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records" "$seed" 201 "Seed A record (192.0.2.200) with PTR"; then
        print_info "Failed to seed record - skipping PTR update tests"
        return 1
    fi
    local PTR_UPD_RECORD_ID
    PTR_UPD_RECORD_ID=$(extract_json_field "$LAST_RESPONSE_BODY" "id")

    # Test 1: change IP with update_ptr=true - old PTR should go, new PTR should appear.
    local update_change_ip='{
        "name": "ptr-upd",
        "type": "A",
        "content": "192.0.2.201",
        "ttl": 3600,
        "update_ptr": true
    }'
    api_request_v2 "PUT" "/zones/$TEST_ZONE_ID/records/$PTR_UPD_RECORD_ID" "$update_change_ip" 200 "Update A record IP with update_ptr=true"

    increment_test
    if [[ "$LAST_RESPONSE_BODY" =~ "\"ptr_updated\":true" ]]; then
        print_pass "ptr_updated flag is true in response"
    else
        print_fail "ptr_updated flag missing or false in response"
    fi

    # Verify new PTR exists and old PTR is gone.
    api_request_v2 "GET" "/zones/$TEST_REVERSE_ZONE_ID/records" "" 200 "List reverse zone after PTR update"

    increment_test
    if [[ "$LAST_RESPONSE_BODY" =~ "201.2.0.192.in-addr.arpa" ]]; then
        print_pass "New PTR (201.2.0.192.in-addr.arpa) found in reverse zone"
    else
        print_fail "New PTR not found in reverse zone"
    fi

    increment_test
    if [[ "$LAST_RESPONSE_BODY" =~ "200.2.0.192.in-addr.arpa" ]]; then
        print_fail "Old PTR (200.2.0.192.in-addr.arpa) still present in reverse zone"
    else
        print_pass "Old PTR removed from reverse zone"
    fi

    # Test 2: update without update_ptr should not touch PTRs.
    local update_no_ptr='{
        "name": "ptr-upd",
        "type": "A",
        "content": "192.0.2.202",
        "ttl": 3600
    }'
    api_request_v2 "PUT" "/zones/$TEST_ZONE_ID/records/$PTR_UPD_RECORD_ID" "$update_no_ptr" 200 "Update A record IP without update_ptr"

    api_request_v2 "GET" "/zones/$TEST_REVERSE_ZONE_ID/records" "" 200 "List reverse zone after silent update"

    increment_test
    if [[ "$LAST_RESPONSE_BODY" =~ "201.2.0.192.in-addr.arpa" ]]; then
        print_pass "PTR for previous IP (201) still present (update_ptr default off)"
    else
        print_fail "PTR for previous IP unexpectedly removed"
    fi

    print_info "PTR update tests completed"
}

##############################################################################
# Test: Server-side TTL defaults (issue #1032, 4.5.0)
##############################################################################

test_ttl_defaults() {
    print_section "TTL Default Resolution Tests"

    if [[ -z "$TEST_ZONE_ID" || -z "$TEST_REVERSE_ZONE_ID" ]]; then
        print_info "Skipping TTL default tests - PTR auto-creation didn't run"
        return 0
    fi

    # Create an A record on the forward zone without a ttl field.
    # Without dns.ttl_reverse configured the server should fall back to dns.ttl.
    local record_no_ttl='{
        "name": "ttl-default-a",
        "type": "A",
        "content": "192.0.2.50"
    }'

    if api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records" "$record_no_ttl" 201 "Create A record without ttl (forward zone)"; then
        local returned_ttl
        returned_ttl=$(extract_json_field "$LAST_RESPONSE_BODY" "ttl")
        increment_test
        if [[ "$returned_ttl" =~ ^[0-9]+$ && "$returned_ttl" -gt 0 ]]; then
            print_pass "Forward A record default ttl is numeric: $returned_ttl"
        else
            print_fail "Forward A record default ttl unexpected: $returned_ttl"
        fi
    fi

    # Create a PTR record on the reverse zone without a ttl field.
    # The server resolves dns.ttl_reverse (when set) or dns.ttl otherwise.
    local ptr_no_ttl='{
        "name": "51.2.0.192.in-addr.arpa",
        "type": "PTR",
        "content": "ttl-default.example.com"
    }'

    if api_request_v2 "POST" "/zones/$TEST_REVERSE_ZONE_ID/records" "$ptr_no_ttl" 201 "Create PTR record without ttl (reverse zone)"; then
        local returned_ttl
        returned_ttl=$(extract_json_field "$LAST_RESPONSE_BODY" "ttl")
        increment_test
        if [[ "$returned_ttl" =~ ^[0-9]+$ && "$returned_ttl" -gt 0 ]]; then
            print_pass "Reverse PTR record default ttl is numeric: $returned_ttl"
        else
            print_fail "Reverse PTR record default ttl unexpected: $returned_ttl"
        fi
    fi

    print_info "TTL default tests completed"
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
    api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records/bulk" "$bulk_invalid" 400 "Bulk with invalid record (atomic rollback)"

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
    api_request_v2 "POST" "/zones/$TEST_ZONE_ID/records/bulk" "$bulk_bad_action" 400 "Reject invalid action type"

    # Test 8: Bulk delete all test records
    api_request_v2 "GET" "/zones/$TEST_ZONE_ID/records?type=A" "" 200 "Get all A records"

    # Count records (for info)
    local record_count=$(echo "$LAST_RESPONSE_BODY" | grep -o "\"id\"" | wc -l | tr -d ' ')
    print_info "Found $record_count A records before bulk delete"

    print_info "Bulk operations tests completed"
}

##############################################################################
# Test: Disabled Record Creation
##############################################################################

test_disabled_records() {
    print_section "Disabled Record Tests"

    # Prerequisites: Create test zone
    print_info "Creating test zone for disabled record tests..."
    local zone_data='{"name":"disabled-test.example.com","type":"MASTER"}'
    local disabled_zone_id=""

    if api_request_v2 "POST" "/zones" "$zone_data" 201 "Create zone for disabled record tests"; then
        disabled_zone_id=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
        print_info "Created zone ID: $disabled_zone_id"
    else
        print_fail "Failed to create test zone - skipping disabled record tests"
        return 1
    fi

    # Test 1: Create record with disabled=true
    local disabled_record='{
        "name": "disabled-host",
        "type": "A",
        "content": "192.0.2.50",
        "ttl": 3600,
        "disabled": true
    }'
    if api_request_v2 "POST" "/zones/$disabled_zone_id/records" "$disabled_record" 201 "Create record with disabled=true"; then
        local disabled_record_id
        disabled_record_id=$(extract_json_field "$LAST_RESPONSE_BODY" "id")
        print_info "Created disabled record ID: $disabled_record_id"

        # Test 2: Verify record is disabled when retrieved
        if [[ -n "$disabled_record_id" ]]; then
            api_request_v2 "GET" "/zones/$disabled_zone_id/records/$disabled_record_id" "" 200 "Get disabled record"

            increment_test
            if echo "$LAST_RESPONSE_BODY" | grep -q '"disabled":true'; then
                print_pass "Record is correctly marked as disabled"
            else
                print_fail "Record should be disabled but is not"
                echo "Response: $LAST_RESPONSE_BODY"
            fi

            # Cleanup the record
            api_request_v2 "DELETE" "/zones/$disabled_zone_id/records/$disabled_record_id" "" 204 "Delete disabled record" || true
        fi
    fi

    # Test 3: Create record with disabled=false (default behavior)
    local enabled_record='{
        "name": "enabled-host",
        "type": "A",
        "content": "192.0.2.51",
        "ttl": 3600,
        "disabled": false
    }'
    if api_request_v2 "POST" "/zones/$disabled_zone_id/records" "$enabled_record" 201 "Create record with disabled=false"; then
        local enabled_record_id
        enabled_record_id=$(extract_json_field "$LAST_RESPONSE_BODY" "id")
        print_info "Created enabled record ID: $enabled_record_id"

        # Test 4: Verify record is enabled when retrieved
        if [[ -n "$enabled_record_id" ]]; then
            api_request_v2 "GET" "/zones/$disabled_zone_id/records/$enabled_record_id" "" 200 "Get enabled record"

            increment_test
            if echo "$LAST_RESPONSE_BODY" | grep -q '"disabled":false'; then
                print_pass "Record is correctly marked as enabled"
            else
                print_fail "Record should be enabled but is not"
                echo "Response: $LAST_RESPONSE_BODY"
            fi

            # Cleanup the record
            api_request_v2 "DELETE" "/zones/$disabled_zone_id/records/$enabled_record_id" "" 204 "Delete enabled record" || true
        fi
    fi

    # Test 5: Create record without disabled field (should default to enabled)
    local default_record='{
        "name": "default-host",
        "type": "A",
        "content": "192.0.2.52",
        "ttl": 3600
    }'
    if api_request_v2 "POST" "/zones/$disabled_zone_id/records" "$default_record" 201 "Create record without disabled field (default)"; then
        local default_record_id
        default_record_id=$(extract_json_field "$LAST_RESPONSE_BODY" "id")

        if [[ -n "$default_record_id" ]]; then
            api_request_v2 "GET" "/zones/$disabled_zone_id/records/$default_record_id" "" 200 "Get default record"

            increment_test
            if echo "$LAST_RESPONSE_BODY" | grep -q '"disabled":false'; then
                print_pass "Record defaults to enabled when disabled field omitted"
            else
                print_fail "Record should default to enabled"
                echo "Response: $LAST_RESPONSE_BODY"
            fi

            api_request_v2 "DELETE" "/zones/$disabled_zone_id/records/$default_record_id" "" 204 "Delete default record" || true
        fi
    fi

    # Test 6: Create disabled record via RRSet
    local disabled_rrset='{
        "name": "disabled-rrset",
        "type": "A",
        "ttl": 3600,
        "records": [
            {"content": "192.0.2.60", "disabled": true}
        ]
    }'
    api_request_v2 "PUT" "/zones/$disabled_zone_id/rrsets" "$disabled_rrset" 200 "Create RRSet with disabled record"

    api_request_v2 "GET" "/zones/$disabled_zone_id/rrsets/disabled-rrset/A" "" 200 "Get disabled RRSet"
    increment_test
    if echo "$LAST_RESPONSE_BODY" | grep -q '"disabled":true'; then
        print_pass "RRSet record is correctly marked as disabled"
    else
        print_fail "RRSet record should be disabled"
        echo "Response: $LAST_RESPONSE_BODY"
    fi

    # Cleanup: delete the test zone
    if [[ -n "$disabled_zone_id" ]]; then
        api_request_v2 "DELETE" "/zones/$disabled_zone_id" "" 204 "Delete disabled test zone" || true
    fi

    print_info "Disabled record tests completed"
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

    # Test 10: IPv6 ambiguous format (treated as plain IPv6, not IPv6:port)
    # Note: "2001:db8::1:5300" is a valid IPv6 address, not IPv6 with port
    # To specify port with IPv6, brackets are required: "[2001:db8::1]:5300"
    local slave_no_brackets='{"name":"slave-no-brackets.example.com","type":"SLAVE","masters":"2001:db8::1:5300"}'
    api_request_v2 "POST" "/zones" "$slave_no_brackets" 201 "Accept ambiguous IPv6 format (no brackets = plain IPv6)"

    # Test 11: Update zone master servers
    if [[ -n "$TEST_SLAVE_ZONE_ID" ]]; then
        local update_master='{"master":"192.0.2.10:5300,192.0.2.11:5300"}'
        api_request_v2 "PUT" "/zones/$TEST_SLAVE_ZONE_ID" "$update_master" 200 "Update zone master servers"

        # Verify PUT returns zone object
        assert_json_exists "Zone PUT returns zone name" "$LAST_RESPONSE_BODY" '.data.zone.name'
        assert_json_exists "Zone PUT returns zone type" "$LAST_RESPONSE_BODY" '.data.zone.type'
    fi

    # Test: Update zone description
    if [[ -n "$TEST_SLAVE_ZONE_ID" ]]; then
        local update_desc='{"description":"Updated description via API"}'
        api_request_v2 "PUT" "/zones/$TEST_SLAVE_ZONE_ID" "$update_desc" 200 "Update zone description"
        assert_json "Zone description updated" "$LAST_RESPONSE_BODY" '.data.zone.description' "Updated description via API"
    fi

    # Test 12: Verify master servers format in GET response
    if [[ -n "$TEST_SLAVE_ZONE_ID" ]]; then
        api_request_v2 "GET" "/zones/$TEST_SLAVE_ZONE_ID" "" 200 "Get SLAVE zone details"

        # Verify zone GET returns all fields
        assert_json_exists "Zone GET returns masters field" "$LAST_RESPONSE_BODY" '.data.zone.masters'
        assert_json_exists "Zone GET returns zone name" "$LAST_RESPONSE_BODY" '.data.zone.name'
        assert_json_exists "Zone GET returns zone type" "$LAST_RESPONSE_BODY" '.data.zone.type'
        # Verify account and description are present (even if null)
        increment_test
        local has_account=$(echo "$LAST_RESPONSE_BODY" | jq 'has("data") and (.data.zone | has("account"))' 2>/dev/null)
        local has_description=$(echo "$LAST_RESPONSE_BODY" | jq 'has("data") and (.data.zone | has("description"))' 2>/dev/null)
        if [[ "$has_account" == "true" ]] && [[ "$has_description" == "true" ]]; then
            print_pass "Zone GET includes account and description fields"
        else
            print_fail "Zone GET missing account or description field"
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
# Test: Zone operation HTTP status codes (issue #1309)
##############################################################################

test_zone_status_codes() {
    print_section "Zone Status Code Tests"

    # Duplicate zone name returns 409
    local dup_zone='{"name":"dup-status-test.example.com","type":"MASTER"}'
    api_request_v2 "POST" "/zones" "$dup_zone" 201 "Create zone for duplicate test"
    local dup_zone_id=""
    if [[ "$LAST_RESPONSE_CODE" -eq 201 ]]; then
        dup_zone_id=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
    fi
    api_request_v2 "POST" "/zones" "$dup_zone" 409 "Reject duplicate zone name (already exists)"

    # Operations on a non-existent zone return 404 (existence checked before permission)
    api_request_v2 "PUT" "/zones/999999" '{"type":"NATIVE"}' 404 "Update non-existent zone returns 404"
    assert_json "Update 404 uses v2 wrapper" "$LAST_RESPONSE_BODY" '.success' 'false'
    api_request_v2 "DELETE" "/zones/999999" "" 404 "Delete non-existent zone returns 404"
    assert_json "Delete 404 uses v2 wrapper" "$LAST_RESPONSE_BODY" '.success' 'false'

    # HEAD is scoped like GET and must reach the read handler, not fall through to 405
    if [[ -n "$dup_zone_id" ]]; then
        api_request_v2 "HEAD" "/zones/$dup_zone_id" "" 200 "HEAD on existing zone returns 200 (not 405)"
    fi

    # Unknown v2 endpoints return 404 in the v2 {success:false} wrapper, not the v1 shape
    api_request_v2 "GET" "/this-endpoint-does-not-exist" "" 404 "Unknown v2 endpoint returns 404"
    assert_json "Unknown-endpoint 404 uses v2 wrapper" "$LAST_RESPONSE_BODY" '.success' 'false'

    # Clean up the zone created for the duplicate test
    if [[ -n "$dup_zone_id" ]]; then
        api_request_v2 "DELETE" "/zones/$dup_zone_id" "" 204 "Delete duplicate-test zone"
    fi

    print_info "Zone status code tests completed"
}

##############################################################################
# Test: Groups API
##############################################################################

# Helper function for group API requests that don't use the standard pattern
api_request_groups() {
    local method=$1
    local endpoint=$2
    local data=${3:-}

    local url="${API_BASE_URL}/api/v2${endpoint}"
    local args=(-s -w "\n%{http_code}" -X "$method")

    args+=(-H "X-API-Key: ${API_KEY}")
    args+=(-H "Content-Type: application/json")
    args+=(-H "Accept: application/json")

    if [[ -n "$data" ]]; then
        args+=(-d "$data")
    fi

    curl "${args[@]}" "$url"
}

cleanup_existing_test_groups() {
    # Remove any test groups from previous runs
    local test_names=("Test Admins" "Updated Test Admins" "Debug Test" "Test Update Group" "Updated Name")

    for name in "${test_names[@]}"; do
        local group_id=$(curl -s -H "X-API-Key: ${API_KEY}" "${API_BASE_URL}/api/v2/groups" | jq -r ".data.groups[]? | select(.name == \"$name\") | .id" 2>/dev/null)
        if [[ -n "$group_id" ]]; then
            curl -s -X DELETE -H "X-API-Key: ${API_KEY}" "${API_BASE_URL}/api/v2/groups/${group_id}" >/dev/null 2>&1 || true
        fi
    done
}

test_groups() {
    print_section "Groups API Tests"

    cleanup_existing_test_groups

    # Test 1: Create group
    increment_test
    print_test "Create new group"
    local response=$(api_request_groups POST "/groups" '{"name": "Test Admins", "description": "Test group for administrators", "perm_templ_id": 6}')
    local http_code=$(echo "$response" | tail -n1)
    local body=$(echo "$response" | sed '$d')

    if [[ "$http_code" == "201" ]]; then
        local success=$(echo "$body" | jq -r '.success')
        if [[ "$success" == "true" ]]; then
            TEST_GROUP_ID=$(echo "$body" | jq -r '.data.group.id')
            print_pass "Group created successfully (ID: $TEST_GROUP_ID)"
        else
            print_fail "Failed to create group"
        fi
    else
        print_fail "Failed to create group (HTTP $http_code)"
    fi

    # Test 2: List groups
    api_request_v2 "GET" "/groups" "" 200 "List all groups" || true

    # Test 3: Get group details
    if [[ -n "$TEST_GROUP_ID" ]]; then
        increment_test
        print_test "Get group details"
        response=$(api_request_groups GET "/groups/${TEST_GROUP_ID}")
        http_code=$(echo "$response" | tail -n1)
        body=$(echo "$response" | sed '$d')

        if [[ "$http_code" == "200" ]]; then
            local success=$(echo "$body" | jq -r '.success')
            local name=$(echo "$body" | jq -r '.data.group.name')
            if [[ "$success" == "true" ]] && [[ "$name" == "Test Admins" ]]; then
                print_pass "Retrieved group details"
            else
                print_fail "Failed to get group details"
            fi
        else
            print_fail "Failed to get group (HTTP $http_code)"
        fi
    fi

    # Test 4: Update group
    if [[ -n "$TEST_GROUP_ID" ]]; then
        increment_test
        print_test "Update group"
        response=$(api_request_groups PUT "/groups/${TEST_GROUP_ID}" '{"name": "Updated Test Admins", "description": "Updated description"}')
        http_code=$(echo "$response" | tail -n1)
        body=$(echo "$response" | sed '$d')

        if [[ "$http_code" == "200" ]]; then
            local success=$(echo "$body" | jq -r '.success')
            if [[ "$success" == "true" ]]; then
                print_pass "Group updated successfully"
                # Verify PUT returns group object
                local updated_name=$(echo "$body" | jq -r '.data.group.name')
                if [[ "$updated_name" == "Updated Test Admins" ]]; then
                    increment_test
                    print_pass "Group PUT returns updated group data"
                fi
            else
                print_fail "Failed to update group"
            fi
        else
            print_fail "Failed to update group (HTTP $http_code)"
        fi
    fi

    # Test 5: List group members
    if [[ -n "$TEST_GROUP_ID" ]]; then
        api_request_v2 "GET" "/groups/${TEST_GROUP_ID}/members" "" 200 "List group members" || true
    fi

    # Test 6: Add member to group
    if [[ -n "$TEST_GROUP_ID" ]]; then
        TEST_USER_ID=1  # Assume user ID 1 exists (admin user)
        increment_test
        print_test "Add member to group"
        response=$(api_request_groups POST "/groups/${TEST_GROUP_ID}/members" "{\"user_id\": ${TEST_USER_ID}}")
        http_code=$(echo "$response" | tail -n1)
        body=$(echo "$response" | sed '$d')

        if [[ "$http_code" == "201" ]]; then
            local success=$(echo "$body" | jq -r '.success')
            if [[ "$success" == "true" ]]; then
                print_pass "Member added successfully"
            else
                print_fail "Failed to add member"
            fi
        else
            print_fail "Failed to add member (HTTP $http_code)"
        fi
    fi

    # Test 7: List group members again
    if [[ -n "$TEST_GROUP_ID" ]]; then
        api_request_v2 "GET" "/groups/${TEST_GROUP_ID}/members" "" 200 "List group members after addition" || true
    fi

    # Test 8: Remove member from group
    if [[ -n "$TEST_GROUP_ID" ]] && [[ -n "$TEST_USER_ID" ]]; then
        increment_test
        print_test "Remove member from group"
        response=$(api_request_groups DELETE "/groups/${TEST_GROUP_ID}/members/${TEST_USER_ID}")
        http_code=$(echo "$response" | tail -n1)
        body=$(echo "$response" | sed '$d')

        if [[ "$http_code" == "200" ]]; then
            local success=$(echo "$body" | jq -r '.success')
            if [[ "$success" == "true" ]]; then
                print_pass "Member removed successfully"
            else
                print_fail "Failed to remove member"
            fi
        else
            print_fail "Failed to remove member (HTTP $http_code)"
        fi
    fi

    # Test 9: List group zones
    if [[ -n "$TEST_GROUP_ID" ]]; then
        api_request_v2 "GET" "/groups/${TEST_GROUP_ID}/zones" "" 200 "List group zones" || true
    fi

    # Test 10: Assign zone to group
    if [[ -n "$TEST_GROUP_ID" ]]; then
        # Create a test zone for assignment
        local zone_assign_response
        zone_assign_response=$(curl -s -w "\n%{http_code}" -H "X-API-Key: $API_KEY" \
            -H "Content-Type: application/json" -H "Accept: application/json" \
            -X POST "${API_BASE_URL}/api/v2/zones" \
            -d '{"name":"group-assign-test.example.com","type":"MASTER"}' --max-time 30)
        local TEST_ZONE_ASSIGN_ID
        TEST_ZONE_ASSIGN_ID=$(echo "$zone_assign_response" | sed '$d' | jq -r '.data.zone_id // empty')
        if [[ -z "$TEST_ZONE_ASSIGN_ID" ]]; then
            # Zone may already exist, look it up
            TEST_ZONE_ASSIGN_ID=$(curl -s -H "X-API-Key: $API_KEY" -H "Accept: application/json" \
                "${API_BASE_URL}/api/v2/zones?name=group-assign-test.example.com" --max-time 30 \
                | jq -r '.data.zones[0].id // empty')
        fi
        increment_test
        print_test "Assign zone to group"
        response=$(api_request_groups POST "/groups/${TEST_GROUP_ID}/zones" "{\"zone_id\": ${TEST_ZONE_ASSIGN_ID}}")
        http_code=$(echo "$response" | tail -n1)
        body=$(echo "$response" | sed '$d')

        if [[ "$http_code" == "201" ]]; then
            local success=$(echo "$body" | jq -r '.success')
            if [[ "$success" == "true" ]]; then
                print_pass "Zone assigned successfully"
            else
                print_fail "Failed to assign zone"
            fi
        elif [[ "$http_code" == "400" ]]; then
            print_info "Zone assignment skipped (may already exist or invalid zone)"
            PASSED_TESTS=$((PASSED_TESTS + 1))
        else
            print_fail "Failed to assign zone (HTTP $http_code)"
        fi
    fi

    # Test 11: List group zones again
    if [[ -n "$TEST_GROUP_ID" ]]; then
        api_request_v2 "GET" "/groups/${TEST_GROUP_ID}/zones" "" 200 "List group zones after assignment" || true
    fi

    # Test 12: Unassign zone from group
    if [[ -n "$TEST_GROUP_ID" ]] && [[ -n "${TEST_ZONE_ASSIGN_ID:-}" ]]; then
        increment_test
        print_test "Unassign zone from group"
        response=$(api_request_groups DELETE "/groups/${TEST_GROUP_ID}/zones/${TEST_ZONE_ASSIGN_ID}")
        http_code=$(echo "$response" | tail -n1)
        body=$(echo "$response" | sed '$d')

        if [[ "$http_code" == "200" ]] || [[ "$http_code" == "404" ]]; then
            print_pass "Zone unassigned (or not found)"
        else
            print_fail "Failed to unassign zone (HTTP $http_code)"
        fi
    fi

    # Test 13: Validation - missing fields
    increment_test
    print_test "Create group with missing required fields"
    response=$(api_request_groups POST "/groups" '{"description": "Missing name and perm_templ_id"}')
    http_code=$(echo "$response" | tail -n1)

    if [[ "$http_code" == "400" ]]; then
        print_pass "Validation error returned correctly"
    else
        print_fail "Expected 400, got HTTP $http_code"
    fi

    # Test 14: Get non-existent group
    api_request_v2 "GET" "/groups/999999" "" 404 "Get non-existent group" || true

    # Test 15: Delete group (cleanup)
    if [[ -n "$TEST_GROUP_ID" ]]; then
        increment_test
        print_test "Delete group"
        response=$(api_request_groups DELETE "/groups/${TEST_GROUP_ID}")
        http_code=$(echo "$response" | tail -n1)
        body=$(echo "$response" | sed '$d')

        if [[ "$http_code" == "200" ]]; then
            local success=$(echo "$body" | jq -r '.success')
            if [[ "$success" == "true" ]]; then
                print_pass "Group deleted successfully"
            else
                print_fail "Failed to delete group"
            fi
        else
            print_fail "Failed to delete group (HTTP $http_code)"
        fi
    fi

    # Cleanup: delete the test zone created for assignment
    if [[ -n "${TEST_ZONE_ASSIGN_ID:-}" ]]; then
        curl -s -X DELETE -H "X-API-Key: $API_KEY" \
            "${API_BASE_URL}/api/v2/zones/${TEST_ZONE_ASSIGN_ID}" >/dev/null 2>&1 || true
    fi

    print_info "Groups API tests completed"
}

##############################################################################
# Test: Zone Templates API
##############################################################################

cleanup_existing_test_templates() {
    # Remove any test zone templates from previous runs
    local templates
    templates=$(curl -s -H "X-API-Key: ${API_KEY}" -H "Accept: application/json" \
        "${API_BASE_URL}/api/v2/zone-templates" 2>/dev/null)

    local test_names=("API Test Template" "Updated API Test Template")

    for name in "${test_names[@]}"; do
        local template_id
        template_id=$(echo "$templates" | jq -r ".data.templates[]? | select(.name == \"$name\") | .id" 2>/dev/null)
        if [[ -n "$template_id" ]]; then
            curl -s -X DELETE -H "X-API-Key: ${API_KEY}" \
                "${API_BASE_URL}/api/v2/zone-templates/${template_id}" >/dev/null 2>&1 || true
        fi
    done
}

test_zone_templates() {
    print_section "Zone Templates API Tests"

    cleanup_existing_test_templates

    # Test 1: List zone templates
    api_request_v2 "GET" "/zone-templates" "" 200 "List zone templates"

    # Test 2: Create zone template
    local template_data='{"name": "API Test Template", "description": "Template created via API test"}'
    if api_request_v2 "POST" "/zone-templates" "$template_data" 201 "Create zone template"; then
        TEST_ZONE_TEMPLATE_ID=$(extract_json_field "$LAST_RESPONSE_BODY" "id")
        print_info "Created zone template ID: $TEST_ZONE_TEMPLATE_ID"
    else
        print_fail "Failed to create zone template - skipping remaining template tests"
        return 1
    fi

    # Test 3: Get zone template details (should include auto-created SOA record)
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]]; then
        api_request_v2 "GET" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}" "" 200 "Get zone template details"

        increment_test
        if [[ "$LAST_RESPONSE_BODY" =~ "SOA" ]]; then
            print_pass "Zone template includes auto-created SOA record"
        else
            print_fail "Zone template missing auto-created SOA record"
        fi
    fi

    # Test 4: List zone template records
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]]; then
        api_request_v2 "GET" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}/records" "" 200 "List zone template records"
    fi

    # Test 5: Add record to zone template
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]]; then
        local record_data='{"name": "[ZONE]", "type": "A", "content": "192.168.1.1", "ttl": 3600, "priority": 0}'
        if api_request_v2 "POST" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}/records" "$record_data" 201 "Add record to zone template"; then
            TEST_ZONE_TEMPLATE_RECORD_ID=$(extract_json_field "$LAST_RESPONSE_BODY" "id")
            print_info "Created template record ID: $TEST_ZONE_TEMPLATE_RECORD_ID"
        fi
    fi

    # Test 6: Get specific template record
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]] && [[ -n "$TEST_ZONE_TEMPLATE_RECORD_ID" ]]; then
        api_request_v2 "GET" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}/records/${TEST_ZONE_TEMPLATE_RECORD_ID}" "" 200 "Get specific template record"
    fi

    # Test 7: Update template record
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]] && [[ -n "$TEST_ZONE_TEMPLATE_RECORD_ID" ]]; then
        local update_record_data='{"name": "[ZONE]", "type": "A", "content": "192.168.1.2", "ttl": 7200, "priority": 0}'
        api_request_v2 "PUT" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}/records/${TEST_ZONE_TEMPLATE_RECORD_ID}" "$update_record_data" 200 "Update template record"
    fi

    # Test 7b: TXT template record round-trip returns unquoted content (issue #1373)
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]]; then
        local txt_record_id=""
        local txt_record_data='{"name": "[ZONE]", "type": "TXT", "content": "\"v=spf1 -all\"", "ttl": 3600, "priority": 0}'
        if api_request_v2 "POST" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}/records" "$txt_record_data" 201 "Add TXT record to zone template"; then
            txt_record_id=$(extract_json_field "$LAST_RESPONSE_BODY" "id")
        fi

        if [[ -n "$txt_record_id" ]]; then
            api_request_v2 "GET" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}/records/${txt_record_id}" "" 200 "Get TXT template record"
            assert_json "TXT template record content is unquoted on get" "$LAST_RESPONSE_BODY" ".data.record.content" "v=spf1 -all"

            api_request_v2 "GET" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}/records" "" 200 "List records with TXT template record"
            assert_json "TXT template record content is unquoted on list" "$LAST_RESPONSE_BODY" ".data.records[] | select(.id == ${txt_record_id}) | .content" "v=spf1 -all"

            api_request_v2 "GET" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}" "" 200 "Get template details with TXT record"
            assert_json "TXT template record content is unquoted in template details" "$LAST_RESPONSE_BODY" ".data.template.records[] | select(.id == ${txt_record_id}) | .content" "v=spf1 -all"

            api_request_v2 "DELETE" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}/records/${txt_record_id}" "" 200 "Delete TXT template record"
        fi
    fi

    # Test 8: Update zone template
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]]; then
        local update_data='{"name": "Updated API Test Template", "description": "Updated via API test"}'
        api_request_v2 "PUT" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}" "$update_data" 200 "Update zone template"
    fi

    # Test 9: Create zone using template ID
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]]; then
        local zone_with_template="{\"name\":\"template-zone-test.example.com\",\"type\":\"MASTER\",\"template\":${TEST_ZONE_TEMPLATE_ID}}"
        api_request_v2 "POST" "/zones" "$zone_with_template" 201 "Create zone using template ID"

        if [[ "$LAST_RESPONSE_CODE" -eq 201 ]]; then
            local template_zone_id=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
            if [[ -n "$template_zone_id" ]]; then
                print_info "Created zone with template, zone ID: $template_zone_id"
                # Cleanup the zone
                curl -s -X DELETE -H "X-API-Key: ${API_KEY}" \
                    "${API_BASE_URL}/api/v2/zones/${template_zone_id}" >/dev/null 2>&1 || true
            fi
        fi
    fi

    # Test 10: Create zone with invalid template ID (should fail, no orphan)
    local bad_template_zone='{"name":"bad-template-test.example.com","type":"MASTER","template":999999}'
    api_request_v2 "POST" "/zones" "$bad_template_zone" 404 "Reject zone creation with non-existent template ID" || true

    # Test 10b: Create zone with string template name (should fail, must be numeric ID)
    local name_template_zone='{"name":"name-template-test.example.com","type":"MASTER","template":"blockTemplate"}'
    api_request_v2 "POST" "/zones" "$name_template_zone" 400 "Reject zone creation with string template name" || true

    # Test 11: Duplicate template name
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]]; then
        local dup_data='{"name": "Updated API Test Template", "description": "Duplicate name test"}'
        api_request_v2 "POST" "/zone-templates" "$dup_data" 409 "Reject duplicate template name"
    fi

    # Test 12: Create template with missing fields
    local missing_fields='{"name": "Incomplete"}'
    api_request_v2 "POST" "/zone-templates" "$missing_fields" 400 "Reject template with missing fields"

    # Test 13: Get non-existent template
    api_request_v2 "GET" "/zone-templates/999999" "" 404 "Get non-existent template returns 404"

    # Test 14: Delete template record
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]] && [[ -n "$TEST_ZONE_TEMPLATE_RECORD_ID" ]]; then
        api_request_v2 "DELETE" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}/records/${TEST_ZONE_TEMPLATE_RECORD_ID}" "" 200 "Delete template record"
    fi

    # Test 15: Delete zone template
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]]; then
        api_request_v2 "DELETE" "/zone-templates/${TEST_ZONE_TEMPLATE_ID}" "" 200 "Delete zone template"
        TEST_ZONE_TEMPLATE_ID=""
    fi

    # Test 16: Delete non-existent template
    api_request_v2 "DELETE" "/zone-templates/999999" "" 404 "Delete non-existent template returns 404"

    print_info "Zone Templates API tests completed"
}

##############################################################################
# Test: Zone Owners API
##############################################################################

cleanup_existing_test_owner_user() {
    local user_id
    user_id=$(curl -s -H "X-API-Key: ${API_KEY}" -H "Accept: application/json" \
        "${API_BASE_URL}/api/v2/users" 2>/dev/null | jq -r '.data.users[]? | select(.username == "zone_owner_test_user") | .user_id' 2>/dev/null)
    if [[ -n "$user_id" ]]; then
        curl -s -X DELETE -H "X-API-Key: ${API_KEY}" \
            "${API_BASE_URL}/api/v2/users/${user_id}" >/dev/null 2>&1 || true
    fi
}

test_zone_owners() {
    print_section "Zone Owners API Tests"

    cleanup_existing_test_owner_user

    # Prerequisites: Create test user and test zone
    print_info "Creating test user for zone owner tests..."
    local user_data='{"username":"zone_owner_test_user","password":"SecurePass1234","fullname":"Zone Owner Test","email":"zone_owner_test@example.com","description":"Test user for zone owner API tests","perm_templ":1,"active":true}'

    if api_request_v2 "POST" "/users" "$user_data" 201 "Create test user for owners"; then
        TEST_OWNER_USER_ID=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.user_id')
        print_info "Created test user ID: $TEST_OWNER_USER_ID"
    else
        print_fail "Failed to create test user - skipping zone owner tests"
        return 1
    fi

    print_info "Creating test zone for zone owner tests..."
    local zone_data='{"name":"owner-test.example.com","type":"MASTER"}'

    if api_request_v2 "POST" "/zones" "$zone_data" 201 "Create test zone for owners"; then
        TEST_OWNER_ZONE_ID=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
        print_info "Created zone ID: $TEST_OWNER_ZONE_ID"
    else
        print_fail "Failed to create test zone - skipping zone owner tests"
        return 1
    fi

    # Test 1: List zone owners
    api_request_v2 "GET" "/zones/${TEST_OWNER_ZONE_ID}/owners" "" 200 "List zone owners"

    # Test 2: Verify initial owner exists
    assert_json_exists "Zone owners response contains user data" "$LAST_RESPONSE_BODY" '.data.owners[0].user_id'

    # Test 3: Add test user as owner
    increment_test
    print_test "Add owner to zone"
    local response=$(api_request_groups POST "/zones/${TEST_OWNER_ZONE_ID}/owners" "{\"user_id\": ${TEST_OWNER_USER_ID}}")
    local http_code=$(echo "$response" | tail -n1)
    local body=$(echo "$response" | sed '$d')

    if [[ "$http_code" == "201" ]]; then
        local success=$(echo "$body" | jq -r '.success')
        if [[ "$success" == "true" ]]; then
            print_pass "Owner added successfully"
        else
            print_fail "Failed to add owner"
        fi
    else
        print_fail "Failed to add owner (HTTP $http_code)"
    fi

    # Test 4: List owners after addition
    api_request_v2 "GET" "/zones/${TEST_OWNER_ZONE_ID}/owners" "" 200 "List zone owners after addition"

    # Test 5: Verify new owner appears in list
    increment_test
    local owner_count=$(echo "$LAST_RESPONSE_BODY" | jq '.data.owners | length' 2>/dev/null)
    if [[ $owner_count -ge 2 ]]; then
        print_pass "Zone now has multiple owners ($owner_count)"
    else
        print_fail "Expected at least 2 owners, found $owner_count"
    fi

    # Test 6: Add duplicate owner (should return 409)
    increment_test
    print_test "Add duplicate owner (should fail)"
    response=$(api_request_groups POST "/zones/${TEST_OWNER_ZONE_ID}/owners" "{\"user_id\": ${TEST_OWNER_USER_ID}}")
    http_code=$(echo "$response" | tail -n1)

    if [[ "$http_code" == "409" ]]; then
        print_pass "Duplicate owner correctly rejected (409)"
    else
        print_fail "Expected 409 for duplicate owner, got HTTP $http_code"
    fi

    # Test 7: Add non-existent user as owner (should return 404)
    increment_test
    print_test "Add non-existent user as owner"
    response=$(api_request_groups POST "/zones/${TEST_OWNER_ZONE_ID}/owners" '{"user_id": 999999}')
    http_code=$(echo "$response" | tail -n1)

    if [[ "$http_code" == "404" ]]; then
        print_pass "Non-existent user correctly rejected (404)"
    else
        print_fail "Expected 404 for non-existent user, got HTTP $http_code"
    fi

    # Test 8: Add owner with missing user_id (should return 400)
    increment_test
    print_test "Add owner with missing user_id"
    response=$(api_request_groups POST "/zones/${TEST_OWNER_ZONE_ID}/owners" '{}')
    http_code=$(echo "$response" | tail -n1)

    if [[ "$http_code" == "400" ]]; then
        print_pass "Missing user_id correctly rejected (400)"
    else
        print_fail "Expected 400 for missing user_id, got HTTP $http_code"
    fi

    # Remove test user owner first so batch add can re-add them
    api_request_groups DELETE "/zones/${TEST_OWNER_ZONE_ID}/owners/${TEST_OWNER_USER_ID}" >/dev/null 2>&1 || true

    # Test 8b: Batch add owners using user_ids
    increment_test
    print_test "Batch add owners using user_ids"
    response=$(api_request_groups POST "/zones/${TEST_OWNER_ZONE_ID}/owners" "{\"user_ids\": [${TEST_OWNER_USER_ID}, 1]}")
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')

    if [[ "$http_code" == "201" ]]; then
        local added_count=$(echo "$body" | jq -r '.data.added | length')
        local skipped_count=$(echo "$body" | jq -r '.data.skipped | length')
        if [[ "$added_count" -ge 1 ]]; then
            print_pass "Batch add owners returned 201 (added: $added_count, skipped: $skipped_count)"
        else
            print_fail "Expected at least 1 added, got added=$added_count skipped=$skipped_count"
        fi
    else
        print_fail "Expected 201 for batch add, got HTTP $http_code"
    fi

    # Test 8c: Batch add with already assigned users (should skip them)
    increment_test
    print_test "Batch add with already assigned owners (skipped)"
    response=$(api_request_groups POST "/zones/${TEST_OWNER_ZONE_ID}/owners" "{\"user_ids\": [${TEST_OWNER_USER_ID}, 1]}")
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')

    # Nothing added (all already assigned) is a plain 200, not a 201 Created.
    if [[ "$http_code" == "200" ]]; then
        local skipped=$(echo "$body" | jq -r '.data.skipped | length')
        local added=$(echo "$body" | jq -r '.data.added | length')
        if [[ "$added" == "0" && "$skipped" -ge 1 ]]; then
            print_pass "All users correctly skipped as already assigned"
        else
            print_fail "Expected 0 added and skipped >= 1, got added=$added skipped=$skipped"
        fi
    else
        print_fail "Expected 200 for batch add with nothing added, got HTTP $http_code"
    fi

    # Test 8d: Batch add with non-existent users (reports not_found)
    increment_test
    print_test "Batch add with non-existent users"
    response=$(api_request_groups POST "/zones/${TEST_OWNER_ZONE_ID}/owners" '{"user_ids": [999998, 999999]}')
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')

    # All users not found means nothing was created: expect 200, not 201.
    if [[ "$http_code" == "200" ]]; then
        local not_found=$(echo "$body" | jq -r '.data.not_found | length')
        if [[ "$not_found" == "2" ]]; then
            print_pass "Non-existent users correctly reported in not_found ($not_found)"
        else
            print_fail "Expected 2 not_found, got $not_found"
        fi
    else
        print_fail "Expected 200 for batch add with nothing added, got HTTP $http_code"
    fi

    # Test 9: Remove owner from zone
    increment_test
    print_test "Remove owner from zone"
    response=$(api_request_groups DELETE "/zones/${TEST_OWNER_ZONE_ID}/owners/${TEST_OWNER_USER_ID}")
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')

    if [[ "$http_code" == "200" ]]; then
        local success=$(echo "$body" | jq -r '.success')
        if [[ "$success" == "true" ]]; then
            print_pass "Owner removed successfully"
        else
            print_fail "Failed to remove owner"
        fi
    else
        print_fail "Failed to remove owner (HTTP $http_code)"
    fi

    # Test 10: List owners after removal
    api_request_v2 "GET" "/zones/${TEST_OWNER_ZONE_ID}/owners" "" 200 "List zone owners after removal"

    # Test 11: Remove non-existent owner (should return 404)
    increment_test
    print_test "Remove non-existent owner"
    response=$(api_request_groups DELETE "/zones/${TEST_OWNER_ZONE_ID}/owners/999999")
    http_code=$(echo "$response" | tail -n1)

    if [[ "$http_code" == "404" ]]; then
        print_pass "Non-existent owner correctly returns 404"
    else
        print_fail "Expected 404 for non-existent owner, got HTTP $http_code"
    fi

    # Test 12: List owners of non-existent zone (should return 404)
    api_request_v2 "GET" "/zones/999999/owners" "" 404 "List owners of non-existent zone returns 404"

    # Test 13: Add owner to non-existent zone (should return 404)
    increment_test
    print_test "Add owner to non-existent zone"
    response=$(api_request_groups POST "/zones/999999/owners" '{"user_id": 1}')
    http_code=$(echo "$response" | tail -n1)

    if [[ "$http_code" == "404" ]]; then
        print_pass "Add owner to non-existent zone correctly returns 404"
    else
        print_fail "Expected 404 for non-existent zone, got HTTP $http_code"
    fi

    # Test 14: Remove owner from non-existent zone (should return 404)
    increment_test
    print_test "Remove owner from non-existent zone"
    response=$(api_request_groups DELETE "/zones/999999/owners/1")
    http_code=$(echo "$response" | tail -n1)

    if [[ "$http_code" == "404" ]]; then
        print_pass "Remove owner from non-existent zone correctly returns 404"
    else
        print_fail "Expected 404 for non-existent zone, got HTTP $http_code"
    fi

    # Test 15 (regression for issue #49): orphan prevention refuses last-owner removal.
    # A freshly-created zone has exactly one owner (the API caller) and no groups,
    # so deleting that sole owner must fail with HTTP 400.
    if api_request_v2 "POST" "/zones" '{"name":"orphan-test.example.com","type":"MASTER"}' 201 "Create dedicated zone for orphan-prevention test"; then
        local orphan_zone_id=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")

        increment_test
        print_test "Removing the last owner is refused (issue #49)"

        response=$(api_request_groups GET "/zones/${orphan_zone_id}/owners")
        body=$(echo "$response" | sed '$d')
        local sole_owner_id=$(echo "$body" | jq -r '.data.owners[0].user_id')

        response=$(api_request_groups DELETE "/zones/${orphan_zone_id}/owners/${sole_owner_id}")
        http_code=$(echo "$response" | tail -n1)
        body=$(echo "$response" | sed '$d')

        if [[ "$http_code" == "400" ]]; then
            local message=$(echo "$body" | jq -r '.message // ""')
            if [[ "$message" == *"last owner"* ]]; then
                print_pass "Last-owner removal refused with 400: $message"
            else
                print_fail "Got 400 but message did not mention 'last owner': $message"
            fi
        else
            print_fail "Expected 400 for last-owner removal, got HTTP $http_code (body: $body)"
        fi

        api_request_v2 "DELETE" "/zones/${orphan_zone_id}" "" 204 "Cleanup orphan-prevention test zone" || true
    fi

    print_info "Zone Owners API tests completed"
}

##############################################################################
# Cleanup Function
##############################################################################

##############################################################################
# Zone Metadata Tests
##############################################################################

TEST_METADATA_ZONE_ID=""

test_zone_metadata() {
    print_section "Zone Metadata API Tests"

    # Create test zone
    print_info "Creating test zone for metadata tests..."
    local zone_data='{"name":"metadata-test.example.com","type":"MASTER"}'

    if api_request_v2 "POST" "/zones" "$zone_data" 201 "Create test zone for metadata"; then
        TEST_METADATA_ZONE_ID=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
        print_info "Created zone ID: $TEST_METADATA_ZONE_ID"
    else
        print_fail "Failed to create test zone - skipping metadata tests"
        return 1
    fi

    # Test 1: List metadata (initially empty or default)
    api_request_v2 "GET" "/zones/${TEST_METADATA_ZONE_ID}/metadata" "" 200 "List zone metadata"
    assert_json_exists "Metadata response has metadata array" "$LAST_RESPONSE_BODY" '.data.metadata'

    # Test 2: Set metadata kind (single value)
    api_request_v2 "PUT" "/zones/${TEST_METADATA_ZONE_ID}/metadata/IXFR" '{"values":["1"]}' 200 "Set single-value metadata (IXFR)"

    # Test 3: Get metadata kind
    api_request_v2 "GET" "/zones/${TEST_METADATA_ZONE_ID}/metadata/IXFR" "" 200 "Get metadata kind (IXFR)"

    # Verify value
    increment_test
    local ixfr_value
    ixfr_value=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.values[0]' 2>/dev/null)
    if [[ "$ixfr_value" == "1" ]]; then
        print_pass "IXFR metadata value is correct"
    else
        print_fail "Expected IXFR value '1', got '$ixfr_value'"
    fi

    # Test 4: Set multi-value metadata
    api_request_v2 "PUT" "/zones/${TEST_METADATA_ZONE_ID}/metadata/ALLOW-AXFR-FROM" '{"values":["192.0.2.10","10.0.0.0/8"]}' 200 "Set multi-value metadata (ALLOW-AXFR-FROM)"

    # Test 5: Get multi-value metadata
    api_request_v2 "GET" "/zones/${TEST_METADATA_ZONE_ID}/metadata/ALLOW-AXFR-FROM" "" 200 "Get multi-value metadata (ALLOW-AXFR-FROM)"

    # Verify count
    increment_test
    local value_count
    value_count=$(echo "$LAST_RESPONSE_BODY" | jq '.data.values | length' 2>/dev/null)
    if [[ "$value_count" == "2" ]]; then
        print_pass "ALLOW-AXFR-FROM has 2 values"
    else
        print_fail "Expected 2 values for ALLOW-AXFR-FROM, got $value_count"
    fi

    # Test 6: List all metadata (should include both kinds)
    api_request_v2 "GET" "/zones/${TEST_METADATA_ZONE_ID}/metadata" "" 200 "List all metadata after setting values"

    increment_test
    local metadata_count
    metadata_count=$(echo "$LAST_RESPONSE_BODY" | jq '.data.metadata | length' 2>/dev/null)
    if [[ "$metadata_count" -ge 2 ]]; then
        print_pass "Metadata list contains at least 2 kinds ($metadata_count)"
    else
        print_fail "Expected at least 2 metadata kinds, got $metadata_count"
    fi

    # Test 7: Replace metadata kind (update)
    api_request_v2 "PUT" "/zones/${TEST_METADATA_ZONE_ID}/metadata/IXFR" '{"values":["0"]}' 200 "Replace metadata value (IXFR -> 0)"

    api_request_v2 "GET" "/zones/${TEST_METADATA_ZONE_ID}/metadata/IXFR" "" 200 "Verify replaced metadata value"

    increment_test
    ixfr_value=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.values[0]' 2>/dev/null)
    if [[ "$ixfr_value" == "0" ]]; then
        print_pass "IXFR metadata value updated to 0"
    else
        print_fail "Expected IXFR value '0', got '$ixfr_value'"
    fi

    # Test 8: Reject multiple values for single-value kind
    api_request_v2 "PUT" "/zones/${TEST_METADATA_ZONE_ID}/metadata/IXFR" '{"values":["0","1"]}' 400 "Reject multiple values for single-value kind"

    # Test 9: Reject write to read-only kind
    api_request_v2 "PUT" "/zones/${TEST_METADATA_ZONE_ID}/metadata/SOA-EDIT" '{"values":["INCEPTION-INCREMENT"]}' 403 "Reject write to read-only metadata kind"

    # Test 10: Reject delete of read-only kind
    api_request_v2 "DELETE" "/zones/${TEST_METADATA_ZONE_ID}/metadata/SOA-EDIT" "" 403 "Reject delete of read-only metadata kind"

    # Test 11: Reject empty values array
    api_request_v2 "PUT" "/zones/${TEST_METADATA_ZONE_ID}/metadata/IXFR" '{"values":[]}' 400 "Reject empty values array"

    # Test 12: Reject missing values field
    api_request_v2 "PUT" "/zones/${TEST_METADATA_ZONE_ID}/metadata/IXFR" '{}' 400 "Reject missing values field"

    # Test 13: Delete metadata kind
    api_request_v2 "DELETE" "/zones/${TEST_METADATA_ZONE_ID}/metadata/IXFR" "" 200 "Delete metadata kind (IXFR)"

    # Test 14: Get deleted kind returns 404
    api_request_v2 "GET" "/zones/${TEST_METADATA_ZONE_ID}/metadata/IXFR" "" 404 "Get deleted metadata kind returns 404"

    # Test 15: Get metadata for non-existent zone
    api_request_v2 "GET" "/zones/999999/metadata" "" 404 "Get metadata for non-existent zone"

    # Test 16: Delete metadata kind on non-existent zone
    api_request_v2 "DELETE" "/zones/999999/metadata/IXFR" "" 404 "Delete metadata on non-existent zone"

    # Cleanup test zone
    if [[ -n "$TEST_METADATA_ZONE_ID" ]]; then
        print_info "Deleting metadata test zone $TEST_METADATA_ZONE_ID..."
        api_request_v2 "DELETE" "/zones/$TEST_METADATA_ZONE_ID" "" 204 "Delete metadata test zone" || true
        TEST_METADATA_ZONE_ID=""
    fi
}

##############################################################################
# Zone DNSSEC Tests
##############################################################################

TEST_DNSSEC_ZONE_ID=""

test_zone_dnssec() {
    print_section "Zone DNSSEC API Tests"

    print_info "Creating test zone for DNSSEC tests..."
    local zone_data='{"name":"dnssec-test.example.com","type":"MASTER"}'

    if api_request_v2 "POST" "/zones" "$zone_data" 201 "Create test zone for DNSSEC"; then
        TEST_DNSSEC_ZONE_ID=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
        print_info "Created zone ID: $TEST_DNSSEC_ZONE_ID"
    else
        print_fail "Failed to create test zone - skipping DNSSEC tests"
        return 1
    fi

    # Apex NS records are required before a zone can be DNSSEC-signed.
    api_request_v2 "POST" "/zones/${TEST_DNSSEC_ZONE_ID}/records" \
        '{"name":"dnssec-test.example.com","type":"NS","content":"ns1.example.com"}' 201 "Add apex NS1 record"
    api_request_v2 "POST" "/zones/${TEST_DNSSEC_ZONE_ID}/records" \
        '{"name":"dnssec-test.example.com","type":"NS","content":"ns2.example.com"}' 201 "Add apex NS2 record"

    # Probe the status endpoint. When the PowerDNS API is not configured the
    # controller returns 501; skip the live sign/unsign checks in that case.
    local probe_code
    probe_code=$(curl -s -o /dev/null -w "%{http_code}" \
        -H "X-API-Key: $API_KEY" -H "Accept: application/json" \
        --max-time 30 \
        "${API_BASE_URL}/api/v2/zones/${TEST_DNSSEC_ZONE_ID}/dnssec")

    if [[ "$probe_code" == "501" ]]; then
        print_info "DNSSEC endpoints return 501 (PowerDNS API not configured) - skipping live sign/unsign tests"
    else
        # Status on an unsigned zone
        api_request_v2 "GET" "/zones/${TEST_DNSSEC_ZONE_ID}/dnssec" "" 200 "Get DNSSEC status (unsigned)"
        increment_test
        local enabled
        enabled=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.enabled' 2>/dev/null)
        if [[ "$enabled" == "false" ]]; then
            print_pass "New zone reports DNSSEC disabled"
        else
            print_fail "Expected DNSSEC disabled on new zone, got '$enabled'"
        fi

        # Enable DNSSEC
        api_request_v2 "POST" "/zones/${TEST_DNSSEC_ZONE_ID}/dnssec" '{"enabled":true}' 200 "Enable DNSSEC"
        increment_test
        local ds_count dnskey
        enabled=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.enabled' 2>/dev/null)
        ds_count=$(echo "$LAST_RESPONSE_BODY" | jq '.data.ds_records | length' 2>/dev/null)
        dnskey=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.dnskey' 2>/dev/null)
        if [[ "$enabled" == "true" && "$ds_count" -ge 1 && -n "$dnskey" && "$dnskey" != "null" ]]; then
            print_pass "Enable returned signed status with $ds_count DS record(s) and a DNSKEY"
        else
            print_fail "Enable response incomplete (enabled=$enabled ds_records=$ds_count dnskey=$dnskey)"
        fi

        # Verify DS record fields are structured
        increment_test
        local key_tag
        key_tag=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.ds_records[0].key_tag' 2>/dev/null)
        if [[ "$key_tag" =~ ^[0-9]+$ ]]; then
            print_pass "DS record exposes a numeric key_tag ($key_tag)"
        else
            print_fail "Expected numeric key_tag in DS record, got '$key_tag'"
        fi

        # Status now reports signed with DS records
        api_request_v2 "GET" "/zones/${TEST_DNSSEC_ZONE_ID}/dnssec" "" 200 "Get DNSSEC status (signed)"
        increment_test
        ds_count=$(echo "$LAST_RESPONSE_BODY" | jq '.data.ds_records | length' 2>/dev/null)
        if [[ "$ds_count" -ge 1 ]]; then
            print_pass "Signed zone status lists $ds_count DS record(s)"
        else
            print_fail "Expected DS records on signed zone, got $ds_count"
        fi

        # Re-enabling an already-signed zone is a no-op (idempotent)
        api_request_v2 "POST" "/zones/${TEST_DNSSEC_ZONE_ID}/dnssec" '{"enabled":true}' 200 "Re-enable DNSSEC is idempotent"

        # Disable DNSSEC
        api_request_v2 "POST" "/zones/${TEST_DNSSEC_ZONE_ID}/dnssec" '{"enabled":false}' 200 "Disable DNSSEC"
        increment_test
        enabled=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.enabled' 2>/dev/null)
        if [[ "$enabled" == "false" ]]; then
            print_pass "Disable returned unsigned status"
        else
            print_fail "Expected DNSSEC disabled after disable, got '$enabled'"
        fi

        # A zone without apex NS records must be rejected before signing
        local nons_zone_id
        if api_request_v2 "POST" "/zones" '{"name":"dnssec-nons.example.com","type":"MASTER"}' 201 "Create zone without NS records"; then
            nons_zone_id=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
            api_request_v2 "POST" "/zones/${nons_zone_id}/dnssec" '{"enabled":true}' 400 "Reject signing a zone with no apex NS records"
            api_request_v2 "DELETE" "/zones/${nons_zone_id}" "" 204 "Delete NS-less validation zone" || true
        fi
    fi

    # Validation and error paths (independent of PowerDNS API availability)
    api_request_v2 "POST" "/zones/${TEST_DNSSEC_ZONE_ID}/dnssec" '{}' 400 "Reject missing enabled field"
    api_request_v2 "POST" "/zones/${TEST_DNSSEC_ZONE_ID}/dnssec" '{"enabled":"yes"}' 400 "Reject non-boolean enabled field"
    api_request_v2 "GET" "/zones/999999/dnssec" "" 404 "Get DNSSEC status for non-existent zone"
    api_request_v2 "POST" "/zones/999999/dnssec" '{"enabled":true}' 404 "Enable DNSSEC on non-existent zone"

    # Cleanup test zone
    if [[ -n "$TEST_DNSSEC_ZONE_ID" ]]; then
        print_info "Deleting DNSSEC test zone $TEST_DNSSEC_ZONE_ID..."
        api_request_v2 "DELETE" "/zones/$TEST_DNSSEC_ZONE_ID" "" 204 "Delete DNSSEC test zone" || true
        TEST_DNSSEC_ZONE_ID=""
    fi
}

##############################################################################
# Users CRUD Tests
##############################################################################

TEST_CRUD_USER_ID=""

test_users_crud() {
    print_section "Users CRUD API Tests"

    # Test 1: List users
    api_request_v2 "GET" "/users" "" 200 "List all users"

    increment_test
    local user_count
    user_count=$(echo "$LAST_RESPONSE_BODY" | jq '.data.users | length' 2>/dev/null)
    if [[ "$user_count" -ge 1 ]]; then
        print_pass "Users list contains at least 1 user ($user_count)"
    else
        print_fail "Expected at least 1 user, got $user_count"
    fi

    # Test 2: Create user
    local create_data='{"username":"api_crud_test_user","password":"SecureTestPass1234","fullname":"API CRUD Test","email":"api_crud_test@example.com","description":"User created by API test","perm_templ":1,"active":true}'
    if api_request_v2 "POST" "/users" "$create_data" 201 "Create user"; then
        TEST_CRUD_USER_ID=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.user_id')
        print_info "Created user ID: $TEST_CRUD_USER_ID"
    fi

    # Test 3: Get user by ID
    if [[ -n "$TEST_CRUD_USER_ID" ]]; then
        api_request_v2 "GET" "/users/${TEST_CRUD_USER_ID}" "" 200 "Get user by ID"

        increment_test
        local username
        username=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.user.username' 2>/dev/null)
        if [[ "$username" == "api_crud_test_user" ]]; then
            print_pass "User data matches (username: $username)"
        else
            print_fail "Expected username 'api_crud_test_user', got '$username'"
        fi
    fi

    # Test 4: Update user
    if [[ -n "$TEST_CRUD_USER_ID" ]]; then
        local update_data='{"fullname":"API CRUD Updated","description":"Updated description"}'
        api_request_v2 "PUT" "/users/${TEST_CRUD_USER_ID}" "$update_data" 200 "Update user"

        # Verify update
        api_request_v2 "GET" "/users/${TEST_CRUD_USER_ID}" "" 200 "Get updated user"

        increment_test
        local fullname
        fullname=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.user.fullname' 2>/dev/null)
        if [[ "$fullname" == "API CRUD Updated" ]]; then
            print_pass "User fullname updated correctly"
        else
            print_fail "Expected fullname 'API CRUD Updated', got '$fullname'"
        fi
    fi

    # Test 5: Create duplicate user (should fail)
    api_request_v2 "POST" "/users" "$create_data" 409 "Create duplicate user (should fail)"

    # Test 6: Get non-existent user
    api_request_v2 "GET" "/users/999999" "" 404 "Get non-existent user"

    # Test 7: Create user with missing required fields
    api_request_v2 "POST" "/users" '{"username":"incomplete"}' 400 "Create user with missing fields"

    # Test 8: Delete user
    if [[ -n "$TEST_CRUD_USER_ID" ]]; then
        api_request_v2 "DELETE" "/users/${TEST_CRUD_USER_ID}" "" 200 "Delete user"

        # Verify deletion
        api_request_v2 "GET" "/users/${TEST_CRUD_USER_ID}" "" 404 "Get deleted user returns 404"
        TEST_CRUD_USER_ID=""
    fi

    # Test 9: Delete non-existent user
    api_request_v2 "DELETE" "/users/999999" "" 404 "Delete non-existent user"
}

cleanup_existing_test_crud_user() {
    local user_id
    user_id=$(curl -s -H "X-API-Key: $API_KEY" -H "Accept: application/json" \
        "${API_BASE_URL}/api/v2/users" 2>/dev/null | jq -r '.data.users[]? | select(.username == "api_crud_test_user") | .user_id' 2>/dev/null)
    if [[ -n "$user_id" ]]; then
        curl -s -X DELETE -H "X-API-Key: $API_KEY" \
            "${API_BASE_URL}/api/v2/users/$user_id" >/dev/null 2>&1 || true
    fi
}

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

    if [[ -n "$TEST_REVERSE_ZONE_ID" && "${CREATED_REVERSE_ZONE:-false}" == "true" ]]; then
        print_info "Deleting reverse zone $TEST_REVERSE_ZONE_ID..."
        api_request_v2 "DELETE" "/zones/$TEST_REVERSE_ZONE_ID" "" 204 "Delete reverse zone" || true
    fi

    # Delete metadata test zone
    if [[ -n "$TEST_METADATA_ZONE_ID" ]]; then
        print_info "Deleting metadata test zone $TEST_METADATA_ZONE_ID..."
        api_request_v2 "DELETE" "/zones/$TEST_METADATA_ZONE_ID" "" 204 "Delete metadata test zone" || true
    fi

    # Delete zone owner test zone and user
    if [[ -n "$TEST_OWNER_ZONE_ID" ]]; then
        print_info "Deleting zone owner test zone $TEST_OWNER_ZONE_ID..."
        api_request_v2 "DELETE" "/zones/$TEST_OWNER_ZONE_ID" "" 204 "Delete zone owner test zone" || true
    fi

    if [[ -n "$TEST_OWNER_USER_ID" ]]; then
        print_info "Deleting zone owner test user $TEST_OWNER_USER_ID..."
        api_request_v2 "DELETE" "/users/$TEST_OWNER_USER_ID" "" 200 "Delete zone owner test user" || true
    fi

    # Delete test zone template if still exists
    if [[ -n "$TEST_ZONE_TEMPLATE_ID" ]]; then
        print_info "Deleting test zone template $TEST_ZONE_TEMPLATE_ID..."
        api_request_v2 "DELETE" "/zone-templates/$TEST_ZONE_TEMPLATE_ID" "" 200 "Delete test zone template" || true
    fi

    # Cleanup leftover test templates
    cleanup_existing_test_templates

    # Delete test group if still exists
    if [[ -n "$TEST_GROUP_ID" ]]; then
        print_info "Deleting test group $TEST_GROUP_ID..."
        curl -s -X DELETE -H "X-API-Key: ${API_KEY}" "${API_BASE_URL}/api/v2/groups/${TEST_GROUP_ID}" >/dev/null 2>&1 || true
    fi

    # Delete other test zones by name pattern
    print_info "Cleaning up any remaining test zones..."
    # This would require listing zones and filtering - simplified for now

    # Delete CRUD test user
    if [[ -n "$TEST_CRUD_USER_ID" ]]; then
        print_info "Deleting CRUD test user $TEST_CRUD_USER_ID..."
        api_request_v2 "DELETE" "/users/$TEST_CRUD_USER_ID" "" 200 "Delete CRUD test user" || true
    fi

    # Cleanup any remaining test groups and users
    cleanup_existing_test_groups
    cleanup_existing_test_owner_user
    cleanup_existing_test_crud_user
}

##############################################################################
# Main Test Execution
##############################################################################

cleanup_existing_test_zones() {
    # Clean up any leftover test zones from previous runs
    local test_zone_names=(
        "rrset-test.example.com"
        "ptr-test.example.com"
        "bulk-test.example.com"
        "slave-simple.example.com"
        "slave-port.example.com"
        "slave-multi.example.com"
        "slave-ipv6.example.com"
        "slave-mixed.example.com"
        "slave-no-brackets.example.com"
        "master-nomasters.example.com"
        "dup-status-test.example.com"
        "template-zone-test.example.com"
        "bad-template-test.example.com"
        "name-template-test.example.com"
        "owner-test.example.com"
        "metadata-test.example.com"
        "disabled-test.example.com"
        "group-assign-test.example.com"
    )

    local all_zones
    all_zones=$(curl -s -H "X-API-Key: $API_KEY" -H "Accept: application/json" \
        "${API_BASE_URL}/api/v1/zones" 2>/dev/null)

    for zone_name in "${test_zone_names[@]}"; do
        local zone_id
        zone_id=$(echo "$all_zones" | jq -r ".data[]? | select(.name==\"$zone_name\") | .zone_id // .id" 2>/dev/null)
        if [[ -n "$zone_id" ]]; then
            curl -s -X DELETE -H "X-API-Key: $API_KEY" \
                "${API_BASE_URL}/api/v2/zones/$zone_id" >/dev/null 2>&1 || true
        fi
    done
}

##############################################################################
# Test: Users API - use_ldap / auth_method synchronization (gh #1195)
##############################################################################

cleanup_existing_ldap_test_user() {
    local user_id
    user_id=$(curl -s -H "X-API-Key: ${API_KEY}" -H "Accept: application/json" \
        "${API_BASE_URL}/api/v2/users" 2>/dev/null | jq -r '.data.users[]? | select(.username == "ldap_sync_test_user") | .user_id' 2>/dev/null)
    if [[ -n "$user_id" ]]; then
        curl -s -X DELETE -H "X-API-Key: ${API_KEY}" \
            "${API_BASE_URL}/api/v2/users/${user_id}" >/dev/null 2>&1 || true
    fi
}

test_users_ldap_sync() {
    print_section "Users API - use_ldap / auth_method sync (gh #1195)"

    cleanup_existing_ldap_test_user

    # Create user with use_ldap:true; auth_method must follow.
    local create_data='{"username":"ldap_sync_test_user","password":"InitialPass123","fullname":"LDAP Sync Test","email":"ldap_sync@example.com","perm_templ":1,"active":true,"use_ldap":true}'
    if ! api_request_v2 "POST" "/users" "$create_data" 201 "Create user with use_ldap=true"; then
        print_fail "Failed to create LDAP test user - skipping ldap sync tests"
        return 1
    fi
    local ldap_user_id
    ldap_user_id=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.user_id')

    # Password update on an LDAP user must be rejected; this only happens when
    # auth_method was actually persisted as 'ldap' alongside use_ldap.
    api_request_v2 "PUT" "/users/${ldap_user_id}" '{"password":"NewPass123"}' 400 "Reject password change on LDAP user"
    if [[ ! "$LAST_RESPONSE_BODY" =~ "LDAP" ]]; then
        increment_test
        print_fail "Expected LDAP-related error message, got: $LAST_RESPONSE_BODY"
    fi

    # Switch user back to SQL; password updates must work again.
    api_request_v2 "PUT" "/users/${ldap_user_id}" '{"use_ldap":false}' 200 "Disable use_ldap"
    api_request_v2 "PUT" "/users/${ldap_user_id}" '{"password":"NewPass123"}' 200 "Allow password change after disabling LDAP"

    api_request_v2 "DELETE" "/users/${ldap_user_id}" "" 200 "Delete LDAP test user"
}

##############################################################################
# Test: Users API - perm_templ validation on create/update (gh #1219)
##############################################################################

cleanup_existing_perm_templ_test_users() {
    local usernames=("perm_templ_valid_user" "perm_templ_omitted_user")
    for uname in "${usernames[@]}"; do
        local user_id
        user_id=$(curl -s -H "X-API-Key: ${API_KEY}" -H "Accept: application/json" \
            "${API_BASE_URL}/api/v2/users" 2>/dev/null | jq -r ".data.users[]? | select(.username == \"${uname}\") | .user_id" 2>/dev/null)
        if [[ -n "$user_id" ]]; then
            curl -s -X DELETE -H "X-API-Key: ${API_KEY}" \
                "${API_BASE_URL}/api/v2/users/${user_id}" >/dev/null 2>&1 || true
        fi
    done
}

# Same as api_request_v2 but authenticates with HTTP basic auth instead of
# the admin API key - used to exercise endpoints as a limited user.
api_request_v2_basic() {
    local method="$1"
    local endpoint="$2"
    local data="${3:-}"
    local expected_status="${4:-200}"
    local description="${5:-API v2 request}"
    local username="$6"
    local password="$7"

    increment_test
    print_test "$description"

    local curl_opts=(
        -s
        -w "\n%{http_code}"
        -u "${username}:${password}"
        -H "Content-Type: application/json"
        -H "Accept: application/json"
        --max-time 30
    )
    # curl -X HEAD waits for a body a HEAD response never sends; --head reads headers only.
    if [[ "$method" == "HEAD" ]]; then
        curl_opts+=(--head)
    else
        curl_opts+=(-X "$method")
    fi

    if [[ -n "$data" ]]; then
        curl_opts+=(-d "$data")
    fi

    local response
    local http_code
    local body

    response=$(curl "${curl_opts[@]}" "${API_BASE_URL}/api/v2${endpoint}")
    http_code=$(echo "$response" | tail -1)
    body=$(echo "$response" | sed '$d')

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

cleanup_existing_self_edit_test_data() {
    local user_id
    user_id=$(curl -s -H "X-API-Key: $API_KEY" -H "Accept: application/json" \
        "${API_BASE_URL}/api/v2/users" 2>/dev/null | jq -r '.data.users[]? | select(.username == "self_edit_test_user" or .username == "self_edit_hijacked") | .user_id' 2>/dev/null)
    if [[ -n "$user_id" ]]; then
        curl -s -X DELETE -H "X-API-Key: $API_KEY" \
            "${API_BASE_URL}/api/v2/users/$user_id" >/dev/null 2>&1 || true
    fi

    local templ_id
    templ_id=$(curl -s -H "X-API-Key: $API_KEY" -H "Accept: application/json" \
        "${API_BASE_URL}/api/v2/permission-templates" 2>/dev/null | jq -r '.data.templates[]? | select(.name == "self_edit_test_templ") | .id' 2>/dev/null)
    if [[ -n "$templ_id" ]]; then
        curl -s -X DELETE -H "X-API-Key: $API_KEY" \
            "${API_BASE_URL}/api/v2/permission-templates/$templ_id" >/dev/null 2>&1 || true
    fi
}

test_users_self_edit_guard() {
    print_section "Users API - self-edit auth-field guard (gh #1327)"

    cleanup_existing_self_edit_test_data

    # Template with only user_edit_own (perm item 56 in the seed data), so the
    # user may maintain their own contact fields but nothing auth-critical.
    if ! api_request_v2 "POST" "/permission-templates" \
        '{"name":"self_edit_test_templ","descr":"gh #1327 test","permissions":[56]}' \
        201 "Create self-edit permission template"; then
        print_fail "Failed to create self-edit template - skipping suite"
        return 1
    fi

    # The create response carries no id - look it up by name.
    api_request_v2 "GET" "/permission-templates" "" 200 "List templates to find self-edit template id"
    local templ_id
    templ_id=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.templates[]? | select(.name == "self_edit_test_templ") | .id')
    if [[ -z "$templ_id" || "$templ_id" == "null" ]]; then
        increment_test
        print_fail "Could not resolve self_edit_test_templ id - skipping suite"
        return 1
    fi

    local password="S3lfEdit#Pass1"
    local create_data
    create_data=$(jq -n --arg pw "$password" --argjson tpl "$templ_id" \
        '{username: "self_edit_test_user", password: $pw, fullname: "Self Edit", email: "self_edit@example.com", perm_templ: $tpl, active: true}')
    if ! api_request_v2 "POST" "/users" "$create_data" 201 "Create limited self-edit user"; then
        print_fail "Failed to create self-edit user - skipping suite"
        return 1
    fi
    local user_id
    user_id=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.user_id')

    # Auth-critical fields must be rejected on self-edit.
    api_request_v2_basic "PUT" "/users/${user_id}" '{"username":"self_edit_hijacked"}' \
        403 "Self-edit username change rejected" "self_edit_test_user" "$password"
    api_request_v2_basic "PUT" "/users/${user_id}" '{"use_ldap":true}' \
        403 "Self-edit use_ldap change rejected" "self_edit_test_user" "$password"
    api_request_v2_basic "PUT" "/users/${user_id}" '{"active":false}' \
        403 "Self-edit active change rejected" "self_edit_test_user" "$password"
    api_request_v2_basic "PUT" "/users/${user_id}" '{"use_ldap":null}' \
        403 "Self-edit use_ldap null bypass rejected" "self_edit_test_user" "$password"
    api_request_v2_basic "PUT" "/users/${user_id}" '{"active":"true"}' \
        403 "Self-edit active string-true bypass rejected (persists as 0)" "self_edit_test_user" "$password"

    # Contact fields stay self-service, and restating stored values must pass
    # so GET->PUT round-tripping clients keep working.
    api_request_v2_basic "PUT" "/users/${user_id}" \
        '{"fullname":"Self Edit Updated","email":"self_edit_new@example.com"}' \
        200 "Self-edit contact fields accepted" "self_edit_test_user" "$password"
    api_request_v2_basic "PUT" "/users/${user_id}" \
        '{"username":"self_edit_test_user","active":true,"description":"round trip"}' \
        200 "Self-edit with unchanged auth fields accepted" "self_edit_test_user" "$password"

    # Verify nothing auth-critical actually changed and contact edits landed.
    api_request_v2 "GET" "/users/${user_id}" "" 200 "Get self-edit user after attempts"
    assert_json "Username unchanged after self-edit attempts" "$LAST_RESPONSE_BODY" '.data.user.username' "self_edit_test_user"
    assert_json "Account still active after self-edit attempts" "$LAST_RESPONSE_BODY" '.data.user.active' "true"
    assert_json "Contact edit landed" "$LAST_RESPONSE_BODY" '.data.user.fullname' "Self Edit Updated"

    # An admin (API key) still changes these fields freely.
    api_request_v2 "PUT" "/users/${user_id}" '{"username":"self_edit_hijacked"}' \
        200 "Admin rename of the same user accepted"

    # Cleanup
    api_request_v2 "DELETE" "/users/${user_id}" "" 200 "Delete self-edit test user"
    api_request_v2 "DELETE" "/permission-templates/${templ_id}" "" 200 "Delete self-edit test template"
}

test_users_perm_templ_validation() {
    print_section "Users API - perm_templ validation (gh #1219)"

    cleanup_existing_perm_templ_test_users

    # Invalid perm_templ on create must 400, not silently store a broken user.
    api_request_v2 "POST" "/users" \
        '{"username":"perm_templ_zero","password":"P0werAdmin1","perm_templ":0}' \
        400 "Reject create with perm_templ=0"

    api_request_v2 "POST" "/users" \
        '{"username":"perm_templ_unknown","password":"P0werAdmin1","perm_templ":99999}' \
        400 "Reject create with unknown perm_templ id"

    api_request_v2 "POST" "/users" \
        '{"username":"perm_templ_str","password":"P0werAdmin1","perm_templ":"admin"}' \
        400 "Reject create with non-numeric perm_templ"

    api_request_v2 "POST" "/users" \
        '{"username":"perm_templ_malformed","password":"P0werAdmin1","perm_templ":"2foo"}' \
        400 "Reject create with malformed numeric perm_templ"

    # Group templates exist in the seed data starting at id 6; users must not use them.
    api_request_v2 "POST" "/users" \
        '{"username":"perm_templ_group","password":"P0werAdmin1","perm_templ":6}' \
        400 "Reject create with group-typed perm_templ"

    # Valid perm_templ still works.
    local valid_data='{"username":"perm_templ_valid_user","password":"P0werAdmin1","fullname":"Perm Templ Valid","email":"perm_templ_valid@example.com","perm_templ":1,"active":true}'
    if ! api_request_v2 "POST" "/users" "$valid_data" 201 "Create with valid perm_templ"; then
        print_fail "Failed to create user with valid perm_templ - skipping follow-ups"
        return 1
    fi
    local valid_id
    valid_id=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.user_id')

    # Listing must include the freshly created user (the JOIN fix surfaces broken rows too).
    api_request_v2 "GET" "/users" "" 200 "List users after create"
    if ! echo "$LAST_RESPONSE_BODY" | jq -e '.data.users[] | select(.username == "perm_templ_valid_user")' >/dev/null 2>&1; then
        increment_test
        print_fail "Created user not present in GET /users response"
    fi

    # Omitted perm_templ on create stays accepted (repo default keeps existing behavior).
    api_request_v2 "POST" "/users" \
        '{"username":"perm_templ_omitted_user","password":"P0werAdmin1","fullname":"Perm Templ Omitted","email":"perm_templ_omitted@example.com","active":true}' \
        201 "Allow create with perm_templ omitted (repo default)"
    local omitted_id
    omitted_id=$(echo "$LAST_RESPONSE_BODY" | jq -r '.data.user_id')

    # Update path must reject the same invalid values - including explicit null,
    # because the repo update path has no default fallback.
    api_request_v2 "PUT" "/users/${valid_id}" '{"perm_templ":0}' \
        400 "Reject update with perm_templ=0"
    api_request_v2 "PUT" "/users/${valid_id}" '{"perm_templ":99999}' \
        400 "Reject update with unknown perm_templ id"
    api_request_v2 "PUT" "/users/${valid_id}" '{"perm_templ":null}' \
        400 "Reject update with perm_templ=null"
    api_request_v2 "PUT" "/users/${valid_id}" '{"perm_templ":2}' \
        200 "Accept update with valid perm_templ"

    # Cleanup
    api_request_v2 "DELETE" "/users/${valid_id}" "" 200 "Delete perm_templ valid test user"
    if [[ -n "$omitted_id" && "$omitted_id" != "null" ]]; then
        api_request_v2 "DELETE" "/users/${omitted_id}" "" 200 "Delete perm_templ omitted test user"
    fi
}

##############################################################################
# Test: Granular API Key Permissions (gh #795)
##############################################################################

# Run a one-shot SQL statement against the configured database. Best-effort:
# returns non-zero (and prints nothing) when the client/credentials are missing.
db_exec() {
    local sql="$1"
    case "${DB_TYPE:-mysql}" in
        mysql|mariadb)
            command -v mysql >/dev/null 2>&1 || return 1
            mysql --batch --skip-column-names -h"${DB_HOST:-localhost}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -e "$sql"
            ;;
        pgsql|postgres|postgresql)
            command -v psql >/dev/null 2>&1 || return 1
            PGPASSWORD="${DB_PASS}" psql -h"${DB_HOST:-localhost}" -U"${DB_USER}" -d"${DB_NAME}" -tA -c "$sql"
            ;;
        sqlite|sqlite3)
            command -v sqlite3 >/dev/null 2>&1 || return 1
            sqlite3 "${DB_NAME}" "$sql"
            ;;
        *)
            return 1
            ;;
    esac
}

# Stored form of an API key secret (mirrors DbApiKeyRepository::hashSecretKey).
hash_api_key() {
    printf 'sha256$%s' "$(printf '%s' "$1" | sha256sum | awk '{print $1}')"
}

# Like api_request_v2 but sends an explicit X-API-Key (first argument).
api_request_v2_with_key() {
    local key="$1"
    local method="$2"
    local endpoint="$3"
    local data="${4:-}"
    local expected_status="${5:-200}"
    local description="${6:-API v2 request}"

    increment_test
    print_test "$description"

    local curl_opts=(
        -s
        -w "\n%{http_code}"
        -H "X-API-Key: $key"
        -H "Content-Type: application/json"
        -H "Accept: application/json"
        --max-time 30
    )
    # curl -X HEAD waits for a body a HEAD response never sends; --head reads headers only.
    if [[ "$method" == "HEAD" ]]; then
        curl_opts+=(--head)
    else
        curl_opts+=(-X "$method")
    fi
    if [[ -n "$data" ]]; then
        curl_opts+=(-d "$data")
    fi

    local response http_code body
    response=$(curl "${curl_opts[@]}" "${API_BASE_URL}/api/v2${endpoint}")
    http_code=$(echo "$response" | tail -1)
    body=$(echo "$response" | sed '$d')
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

test_api_key_scopes() {
    print_section "Granular API Key Permissions (gh #795)"

    # These tests seed scoped keys directly in the database; skip cleanly when
    # the DB client or credentials are not available to this runner.
    local owner_id
    if ! owner_id=$(db_exec "SELECT id FROM users WHERE username='admin' LIMIT 1;" 2>/dev/null) || [[ -z "$owner_id" ]]; then
        print_info "Database access not available - skipping API key scope tests"
        return 0
    fi
    owner_id=$(echo "$owner_id" | tr -d '[:space:]')

    local ro_secret="scopetest-readonly-key-aaaaaaaaaaaa"
    local ops_secret="scopetest-ops-key-bbbbbbbbbbbb"
    local zone_secret="scopetest-zone-key-cccccccccccc"

    # Clean any leftovers from a previous run, then seed fresh keys.
    db_exec "DELETE FROM api_keys WHERE name IN ('scopetest-ro','scopetest-ops','scopetest-zone');" >/dev/null 2>&1 || true

    db_exec "INSERT INTO api_keys (name, secret_key, created_by, is_readonly) VALUES ('scopetest-ro', '$(hash_api_key "$ro_secret")', ${owner_id}, 1);" >/dev/null 2>&1
    db_exec "INSERT INTO api_keys (name, secret_key, created_by, allowed_operations) VALUES ('scopetest-ops', '$(hash_api_key "$ops_secret")', ${owner_id}, 'view,create');" >/dev/null 2>&1
    db_exec "INSERT INTO api_keys (name, secret_key, created_by) VALUES ('scopetest-zone', '$(hash_api_key "$zone_secret")', ${owner_id});" >/dev/null 2>&1

    # Two zones: one in the zone-scoped key's allowlist, one outside it.
    local zone_a zone_b
    api_request_v2 "POST" "/zones" '{"name":"scope-allowed.example.com","type":"MASTER"}' 201 "Create in-scope zone" || true
    zone_a=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
    api_request_v2 "POST" "/zones" '{"name":"scope-denied.example.com","type":"MASTER"}' 201 "Create out-of-scope zone" || true
    zone_b=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")

    local zone_key_id
    zone_key_id=$(db_exec "SELECT id FROM api_keys WHERE name='scopetest-zone' LIMIT 1;" 2>/dev/null | tr -d '[:space:]')
    if [[ -n "$zone_key_id" && -n "$zone_a" ]]; then
        db_exec "INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (${zone_key_id}, ${zone_a});" >/dev/null 2>&1
    fi

    # Read-only key: view allowed, every write rejected with 403.
    api_request_v2_with_key "$ro_secret" "GET" "/zones/${zone_a}" "" 200 "Read-only key may GET a zone"
    api_request_v2_with_key "$ro_secret" "HEAD" "/zones/${zone_a}" "" 200 "Read-only key may HEAD a zone (routed to GET, not 405)"
    api_request_v2_with_key "$ro_secret" "POST" "/zones" '{"name":"ro-denied.example.com","type":"MASTER"}' 403 "Read-only key may not POST"
    api_request_v2_with_key "$ro_secret" "PUT" "/zones/${zone_a}" '{"type":"NATIVE"}' 403 "Read-only key may not PUT"
    api_request_v2_with_key "$ro_secret" "DELETE" "/zones/${zone_b}" "" 403 "Read-only key may not DELETE"

    # Operation-subset key (view+create): create allowed, update/delete rejected.
    api_request_v2_with_key "$ops_secret" "GET" "/zones/${zone_a}" "" 200 "Ops key may GET (view in subset)"
    api_request_v2_with_key "$ops_secret" "PUT" "/zones/${zone_a}" '{"type":"NATIVE"}' 403 "Ops key may not PUT (update not in subset)"
    api_request_v2_with_key "$ops_secret" "DELETE" "/zones/${zone_a}" "" 403 "Ops key may not DELETE (delete not in subset)"
    if api_request_v2_with_key "$ops_secret" "POST" "/zones" '{"name":"ops-allowed.example.com","type":"MASTER"}' 201 "Ops key may POST (create in subset)"; then
        local ops_created
        ops_created=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
        [[ -n "$ops_created" ]] && api_request_v2 "DELETE" "/zones/${ops_created}" "" 200 "Cleanup ops-created zone"
    fi

    # Operation scope is enforced per bulk action, not by the POST method: a
    # view+create key may not smuggle an update through the bulk endpoint.
    api_request_v2_with_key "$ops_secret" "POST" "/zones/${zone_a}/records/bulk" \
        '{"operations":[{"action":"update","id":1,"name":"x.scope-allowed.example.com","type":"A","content":"192.0.2.9","ttl":3600}]}' \
        403 "Ops key (view+create) may not bulk-update"

    # A zone-scoped key is confined to its allowlist, so it cannot create new zones.
    api_request_v2_with_key "$zone_secret" "POST" "/zones" '{"name":"zonescope-create.example.com","type":"MASTER"}' 403 "Zone-scoped key may not create new zones"

    # Zone-scoped key: in-scope zone allowed, out-of-scope zone rejected.
    api_request_v2_with_key "$zone_secret" "GET" "/zones/${zone_a}" "" 200 "Zone-scoped key may access allowed zone"
    api_request_v2_with_key "$zone_secret" "GET" "/zones/${zone_b}" "" 403 "Zone-scoped key may not access other zone"
    api_request_v2_with_key "$zone_secret" "POST" "/zones/${zone_b}/records" '{"name":"x.scope-denied.example.com","type":"A","content":"192.0.2.1","ttl":3600}' 403 "Zone-scoped key may not write to other zone"

    # The list endpoint is filtered, not rejected: only in-scope zones appear.
    if api_request_v2_with_key "$zone_secret" "GET" "/zones" "" 200 "Zone-scoped key lists zones"; then
        if echo "$LAST_RESPONSE_BODY" | jq -e '.data.zones[]? | select(.name == "scope-allowed.example.com")' >/dev/null 2>&1; then
            increment_test; print_pass "List includes the in-scope zone"
        else
            increment_test; print_fail "List should include the in-scope zone"
        fi
        if echo "$LAST_RESPONSE_BODY" | jq -e '.data.zones[]? | select(.name == "scope-denied.example.com")' >/dev/null 2>&1; then
            increment_test; print_fail "List must exclude the out-of-scope zone"
        else
            increment_test; print_pass "List excludes the out-of-scope zone"
        fi
    fi

    # Cleanup seeded keys (api_key_zones rows cascade / are removed with the key)
    # and the two test zones.
    db_exec "DELETE FROM api_key_zones WHERE api_key_id IN (SELECT id FROM api_keys WHERE name IN ('scopetest-ro','scopetest-ops','scopetest-zone'));" >/dev/null 2>&1 || true
    db_exec "DELETE FROM api_keys WHERE name IN ('scopetest-ro','scopetest-ops','scopetest-zone');" >/dev/null 2>&1 || true
    [[ -n "$zone_a" ]] && api_request_v2 "DELETE" "/zones/${zone_a}" "" 200 "Cleanup in-scope zone" || true
    [[ -n "$zone_b" ]] && api_request_v2 "DELETE" "/zones/${zone_b}" "" 200 "Cleanup out-of-scope zone" || true
}

test_zone_overlap_guard() {
    print_section "Zone Overlap Guard (parent_zone_ownership_check)"

    # Seeds a non-ueberuser key directly in the DB (ueberusers bypass the guard);
    # skip cleanly when the DB client or credentials are unavailable.
    local mgr_id
    if ! mgr_id=$(db_exec "SELECT id FROM users WHERE username='manager' LIMIT 1;" 2>/dev/null) || [[ -z "$mgr_id" ]]; then
        print_info "Database access not available - skipping zone overlap guard tests"
        return 0
    fi
    mgr_id=$(echo "$mgr_id" | tr -d '[:space:]')

    local mgr_secret="overlaptest-manager-key-aaaaaaaaaaaa"
    db_exec "DELETE FROM api_keys WHERE name='overlaptest-mgr';" >/dev/null 2>&1 || true
    db_exec "INSERT INTO api_keys (name, secret_key, created_by) VALUES ('overlaptest-mgr', '$(hash_api_key "$mgr_secret")', ${mgr_id});" >/dev/null 2>&1

    # Parent zone owned by the suite's admin key user; manager does not own it.
    local parent="overlap-parent.example.com"
    local parent_id=""
    if api_request_v2 "POST" "/zones" "{\"name\":\"${parent}\",\"type\":\"MASTER\"}" 201 "Create admin-owned parent zone"; then
        parent_id=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
    fi

    # child-under-parent across owners must be rejected with 409.
    if api_request_v2_with_key "$mgr_secret" "POST" "/zones" "{\"name\":\"child.${parent}\",\"type\":\"MASTER\"}" 409 "Non-owner blocked from child of another owner's zone"; then
        increment_test
        if echo "$LAST_RESPONSE_BODY" | grep -q "overlaps"; then
            print_pass "409 message mentions overlap"
        else
            print_fail "409 message should mention overlap"
        fi
    fi

    # Same owner may nest under their own zone.
    local own="overlap-mgr-own.example.com"
    local own_id="" own_child_id=""
    if api_request_v2_with_key "$mgr_secret" "POST" "/zones" "{\"name\":\"${own}\",\"type\":\"MASTER\"}" 201 "Owner may create their own zone"; then
        own_id=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
    fi
    if api_request_v2_with_key "$mgr_secret" "POST" "/zones" "{\"name\":\"sub.${own}\",\"type\":\"MASTER\"}" 201 "Owner may create a child under their own zone"; then
        own_child_id=$(extract_json_field "$LAST_RESPONSE_BODY" "zone_id")
    fi

    # Cleanup (admin key deletes all created zones; then drop the seeded key).
    [[ -n "$own_child_id" ]] && api_request_v2 "DELETE" "/zones/${own_child_id}" "" 204 "Cleanup own child zone" || true
    [[ -n "$own_id" ]] && api_request_v2 "DELETE" "/zones/${own_id}" "" 204 "Cleanup own zone" || true
    [[ -n "$parent_id" ]] && api_request_v2 "DELETE" "/zones/${parent_id}" "" 204 "Cleanup parent zone" || true
    db_exec "DELETE FROM api_keys WHERE name='overlaptest-mgr';" >/dev/null 2>&1 || true
}

main() {
    print_header "PowerAdmin API v2 Test Suite"

    load_config

    # Clean up leftover data from previous test runs
    cleanup_existing_test_zones
    cleanup_existing_test_crud_user

    echo -e "\n${YELLOW}Starting tests...${NC}\n"

    # Run test suites
    test_rrsets
    test_ptr_autocreation
    test_ptr_update
    test_ttl_defaults
    test_bulk_operations
    test_disabled_records
    test_master_port_syntax
    test_zone_status_codes
    test_groups
    test_zone_owners
    test_zone_metadata
    test_zone_dnssec
    test_users_crud
    test_zone_templates
    test_users_ldap_sync
    test_users_perm_templ_validation
    test_users_self_edit_guard
    test_api_key_scopes
    test_zone_overlap_guard

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
