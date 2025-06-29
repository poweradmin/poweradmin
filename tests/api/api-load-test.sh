#!/bin/bash

##############################################################################
# Poweradmin API Load Testing Script
# Stress testing for API endpoints using curl
##############################################################################

set -euo pipefail

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${SCRIPT_DIR}/.env.api-test"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Load configuration
if [[ -f "$CONFIG_FILE" ]]; then
    set -a
    source "$CONFIG_FILE"
    set +a
else
    echo -e "${RED}Error: Configuration file not found: $CONFIG_FILE${NC}"
    exit 1
fi

# Load test parameters
CONCURRENT_REQUESTS=${LOAD_TEST_CONCURRENT:-10}
TOTAL_REQUESTS=${LOAD_TEST_TOTAL:-100}
TEST_DURATION=${LOAD_TEST_DURATION:-60}
RAMP_UP_TIME=${LOAD_TEST_RAMP_UP:-10}

print_header() {
    echo -e "${BLUE}================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}================================${NC}"
}

print_section() {
    echo -e "\n${YELLOW}--- $1 ---${NC}"
}

##############################################################################
# Load Testing Functions
##############################################################################

load_test_endpoint() {
    local endpoint="$1"
    local method="${2:-GET}"
    local description="$3"
    
    print_section "Load Testing: $description"
    echo "Endpoint: $method $endpoint"
    echo "Concurrent requests: $CONCURRENT_REQUESTS"
    echo "Total requests: $TOTAL_REQUESTS"
    echo "Duration: ${TEST_DURATION}s"
    
    local start_time=$(date +%s)
    local successful_requests=0
    local failed_requests=0
    local total_response_time=0
    local min_response_time=999999
    local max_response_time=0
    
    # Create temporary files for results
    local temp_dir=$(mktemp -d)
    local results_file="$temp_dir/results.txt"
    
    # Function to make a single request
    make_request() {
        local request_start=$(date +%s.%N)
        
        local response_code=$(curl -s -w "%{http_code}" \
            -H "X-API-Key: $API_KEY" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -X "$method" \
            --max-time 30 \
            "${API_BASE_URL}/api/v1${endpoint}" \
            -o /dev/null 2>/dev/null || echo "000")
        
        local request_end=$(date +%s.%N)
        local response_time=$(echo "$request_end - $request_start" | bc -l)
        
        echo "$response_code:$response_time" >> "$results_file"
    }
    
    # Run concurrent requests
    local pids=()
    local requests_per_worker=$((TOTAL_REQUESTS / CONCURRENT_REQUESTS))
    
    for ((i=0; i<CONCURRENT_REQUESTS; i++)); do
        (
            for ((j=0; j<requests_per_worker; j++)); do
                make_request
                
                # Add small delay to simulate real usage
                sleep 0.1
            done
        ) &
        pids+=($!)
        
        # Ramp up gradually
        if [[ $RAMP_UP_TIME -gt 0 ]]; then
            sleep $(echo "$RAMP_UP_TIME / $CONCURRENT_REQUESTS" | bc -l)
        fi
    done
    
    # Wait for all background processes
    echo "Running load test..."
    for pid in "${pids[@]}"; do
        wait "$pid"
    done
    
    # Process results
    echo "Processing results..."
    
    while IFS=':' read -r code time; do
        if [[ "$code" == "200" ]]; then
            ((successful_requests++))
        else
            ((failed_requests++))
        fi
        
        local time_ms=$(echo "$time * 1000" | bc -l)
        total_response_time=$(echo "$total_response_time + $time_ms" | bc -l)
        
        if (( $(echo "$time_ms < $min_response_time" | bc -l) )); then
            min_response_time=$time_ms
        fi
        
        if (( $(echo "$time_ms > $max_response_time" | bc -l) )); then
            max_response_time=$time_ms
        fi
    done < "$results_file"
    
    local end_time=$(date +%s)
    local total_time=$((end_time - start_time))
    local total_requests_actual=$((successful_requests + failed_requests))
    local avg_response_time=0
    local requests_per_second=0
    
    if [[ $total_requests_actual -gt 0 ]]; then
        avg_response_time=$(echo "scale=2; $total_response_time / $total_requests_actual" | bc -l)
        requests_per_second=$(echo "scale=2; $total_requests_actual / $total_time" | bc -l)
    fi
    
    # Display results
    echo -e "\n${GREEN}Load Test Results:${NC}"
    echo "Total requests: $total_requests_actual"
    echo "Successful requests: $successful_requests"
    echo "Failed requests: $failed_requests"
    echo "Success rate: $(echo "scale=2; $successful_requests * 100 / $total_requests_actual" | bc -l)%"
    echo "Total time: ${total_time}s"
    echo "Requests per second: $requests_per_second"
    echo "Average response time: ${avg_response_time}ms"
    echo "Min response time: ${min_response_time}ms"
    echo "Max response time: ${max_response_time}ms"
    
    # Cleanup
    rm -rf "$temp_dir"
    
    # Return success if failure rate is acceptable
    local failure_rate=$(echo "scale=2; $failed_requests * 100 / $total_requests_actual" | bc -l)
    if (( $(echo "$failure_rate > 5" | bc -l) )); then
        echo -e "${RED}Warning: High failure rate (${failure_rate}%)${NC}"
        return 1
    fi
    
    return 0
}

##############################################################################
# Stress Testing Scenarios
##############################################################################

stress_test_authentication() {
    load_test_endpoint "/users" "GET" "User List Endpoint"
}

stress_test_zone_listing() {
    load_test_endpoint "/zones" "GET" "Zone List Endpoint"
}

stress_test_zone_creation() {
    # Note: This will create many zones - only run in test environment
    print_section "Stress Testing: Zone Creation"
    echo -e "${YELLOW}Warning: This test creates multiple zones${NC}"
    read -p "Continue? (y/N): " -n 1 -r
    echo
    
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Skipping zone creation stress test"
        return 0
    fi
    
    local temp_dir=$(mktemp -d)
    local results_file="$temp_dir/zone_results.txt"
    local created_zones=()
    
    # Create zones concurrently
    local start_time=$(date +%s)
    local pids=()
    
    for ((i=1; i<=CONCURRENT_REQUESTS; i++)); do
        (
            local zone_name="load-test-${i}-$(date +%s).example.com"
            local zone_data="{\"name\": \"$zone_name\", \"type\": \"NATIVE\"}"
            
            local response=$(curl -s -w "%{http_code}" \
                -H "X-API-Key: $API_KEY" \
                -H "Content-Type: application/json" \
                -X POST \
                -d "$zone_data" \
                "${API_BASE_URL}/api/v1/zones" 2>/dev/null)
            
            local status_code="${response: -3}"
            local body="${response%???}"
            
            if [[ "$status_code" == "201" ]]; then
                local zone_id=$(echo "$body" | jq -r '.data.id' 2>/dev/null || echo "")
                echo "SUCCESS:$zone_id:$zone_name" >> "$results_file"
            else
                echo "FAILED:$status_code:$zone_name" >> "$results_file"
            fi
        ) &
        pids+=($!)
        
        sleep 0.5  # Stagger requests
    done
    
    # Wait for completion
    for pid in "${pids[@]}"; do
        wait "$pid"
    done
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    # Process results and cleanup
    local successful=0
    local failed=0
    
    while IFS=':' read -r status zone_id zone_name; do
        if [[ "$status" == "SUCCESS" && -n "$zone_id" ]]; then
            ((successful++))
            created_zones+=("$zone_id")
        else
            ((failed++))
        fi
    done < "$results_file"
    
    echo "Zone creation results:"
    echo "Successful: $successful"
    echo "Failed: $failed"
    echo "Duration: ${duration}s"
    
    # Cleanup created zones
    if [[ ${#created_zones[@]} -gt 0 ]]; then
        echo "Cleaning up ${#created_zones[@]} created zones..."
        for zone_id in "${created_zones[@]}"; do
            curl -s -X DELETE \
                -H "X-API-Key: $API_KEY" \
                "${API_BASE_URL}/api/v1/zones/$zone_id" >/dev/null 2>&1 || true
        done
        echo "Cleanup completed"
    fi
    
    rm -rf "$temp_dir"
}

##############################################################################
# Memory and Resource Testing
##############################################################################

memory_leak_test() {
    print_section "Memory Leak Detection Test"
    echo "Making repeated requests to detect memory leaks..."
    
    local iterations=1000
    local endpoint="/users"
    
    echo "Running $iterations requests to $endpoint"
    
    for ((i=1; i<=iterations; i++)); do
        curl -s \
            -H "X-API-Key: $API_KEY" \
            -H "Accept: application/json" \
            "${API_BASE_URL}/api/v1${endpoint}" \
            >/dev/null 2>&1 || true
        
        if (( i % 100 == 0 )); then
            echo "Completed $i requests..."
        fi
        
        # Small delay to avoid overwhelming the server
        sleep 0.01
    done
    
    echo "Memory leak test completed"
    echo "Monitor server memory usage for any unusual growth patterns"
}

##############################################################################
# Rate Limiting Tests
##############################################################################

rate_limit_test() {
    print_section "Rate Limiting Test"
    echo "Testing rate limiting behavior..."
    
    local endpoint="/users"
    local rapid_requests=100
    local rate_limited=0
    
    echo "Making $rapid_requests rapid requests..."
    
    for ((i=1; i<=rapid_requests; i++)); do
        local response_code=$(curl -s -w "%{http_code}" \
            -H "X-API-Key: $API_KEY" \
            -H "Accept: application/json" \
            "${API_BASE_URL}/api/v1${endpoint}" \
            -o /dev/null 2>/dev/null || echo "000")
        
        if [[ "$response_code" == "429" ]]; then
            ((rate_limited++))
        fi
        
        # No delay - test rapid requests
    done
    
    echo "Rate limiting results:"
    echo "Total requests: $rapid_requests"
    echo "Rate limited responses (429): $rate_limited"
    
    if [[ $rate_limited -gt 0 ]]; then
        echo -e "${GREEN}Rate limiting is working${NC}"
    else
        echo -e "${YELLOW}No rate limiting detected (may not be implemented)${NC}"
    fi
}

##############################################################################
# Main Execution
##############################################################################

run_load_tests() {
    print_header "API Load Testing Suite"
    
    echo "Configuration:"
    echo "Base URL: $API_BASE_URL"
    echo "Concurrent requests: $CONCURRENT_REQUESTS"
    echo "Total requests: $TOTAL_REQUESTS"
    echo "Test duration: ${TEST_DURATION}s"
    echo "Ramp up time: ${RAMP_UP_TIME}s"
    echo ""
    
    # Check dependencies
    command -v bc >/dev/null 2>&1 || { echo -e "${RED}bc is required but not installed${NC}"; exit 1; }
    command -v jq >/dev/null 2>&1 || { echo -e "${RED}jq is required but not installed${NC}"; exit 1; }
    
    # Run load tests
    stress_test_authentication
    stress_test_zone_listing
    rate_limit_test
    memory_leak_test
    
    # Optional: Zone creation stress test
    echo ""
    echo -e "${YELLOW}Zone creation stress test is available but requires confirmation${NC}"
    echo "Run './api-load-test.sh zones' to run zone creation stress test"
    
    echo ""
    print_header "Load Testing Complete"
}

main() {
    case "${1:-all}" in
        "auth"|"authentication")
            stress_test_authentication
            ;;
        "zones")
            stress_test_zone_creation
            ;;
        "rate-limit")
            rate_limit_test
            ;;
        "memory")
            memory_leak_test
            ;;
        "all"|*)
            run_load_tests
            ;;
    esac
}

# Check if running in interactive mode
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi