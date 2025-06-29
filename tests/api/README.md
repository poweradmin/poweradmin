# Poweradmin API Test Suite (Shell/curl)

This directory contains comprehensive API testing scripts using shell and curl, providing an alternative to PHPUnit-based tests that requires no additional PHP dependencies.

## Quick Start

### 1. Setup
```bash
# Navigate to API test directory
cd tests/api/

# Setup configuration (interactive)
./run-tests.sh setup

# Or manually copy and edit config
cp .env.api-test.example .env.api-test
# Edit .env.api-test with your settings
```

### 2. Run Tests
```bash
# Check prerequisites
./run-tests.sh check

# Run all API tests
./run-tests.sh test all

# Run specific test suites
./run-tests.sh test auth
./run-tests.sh test users
./run-tests.sh test zones
./run-tests.sh test records
./run-tests.sh test security

# Run load tests
./run-tests.sh load all
./run-tests.sh load rate-limit

# Clean up test data
./run-tests.sh clean
```

## Test Scripts

### `run-tests.sh` - Main Test Runner
Simplified interface for running all API tests.

**Commands:**
- `setup` - Interactive configuration setup
- `test [SUITE]` - Run API functional tests
- `load [TYPE]` - Run load/performance tests
- `check` - Verify prerequisites and configuration
- `clean` - Clean up test data

**Examples:**
```bash
./run-tests.sh setup              # Setup configuration
./run-tests.sh check              # Check everything is ready
./run-tests.sh test all           # Run all functional tests
./run-tests.sh test auth          # Run authentication tests only
./run-tests.sh load rate-limit    # Test rate limiting
```

### `api-test.sh` - Comprehensive API Tests
Full API test suite covering all endpoints with success and failure scenarios.

**Test Categories:**
- **Authentication** - API key validation, invalid keys, missing auth
- **User Management** - CRUD operations, validation, pagination
- **Zone Management** - Zone operations, validation, error handling
- **Zone Records** - Record CRUD, type validation, cross-zone access
- **Record Types** - Comprehensive DNS record type validation
- **Security** - SQL injection, XSS, large payloads, invalid JSON
- **Edge Cases** - Unsupported methods, TTL validation, content-type
- **Documentation** - Swagger UI, OpenAPI JSON endpoints
- **Dynamic DNS** - Dynamic update endpoint testing
- **Performance** - Response time measurement

**Usage:**
```bash
./api-test.sh                     # Run all tests
./api-test.sh auth               # Authentication tests only
./api-test.sh users              # User management tests
./api-test.sh zones              # Zone management tests
./api-test.sh records            # Record management tests
./api-test.sh security           # Security tests only
./api-test.sh performance        # Performance tests only
```

### `api-load-test.sh` - Load and Stress Testing
Performance and stress testing for API endpoints.

**Test Types:**
- **Load Testing** - Concurrent requests to endpoints
- **Stress Testing** - High-volume zone creation
- **Rate Limiting** - Rapid request detection
- **Memory Leak** - Repeated requests for memory analysis

**Usage:**
```bash
./api-load-test.sh               # Run all load tests
./api-load-test.sh auth          # Authentication load test
./api-load-test.sh zones         # Zone creation stress test
./api-load-test.sh rate-limit    # Rate limiting test
./api-load-test.sh memory        # Memory leak detection
```

## Configuration

### Environment Variables

Create `.env.api-test` from the example:

```bash
# API Configuration
API_BASE_URL=http://localhost:8080
API_KEY=your-test-api-key-here

# Database (for test data verification)
DB_HOST=localhost
DB_NAME=poweradmin_test
DB_USER=test_user
DB_PASS=test_password
DB_TYPE=mysql

# Test Behavior
TEST_TIMEOUT=30
TEST_CLEANUP=true

# Load Testing
LOAD_TEST_CONCURRENT=10
LOAD_TEST_TOTAL=100
LOAD_TEST_DURATION=60
LOAD_TEST_RAMP_UP=10
```

### Required Dependencies

The scripts require these command-line tools:
- `curl` - HTTP client for API requests
- `jq` - JSON processor for response parsing
- `bc` - Calculator for performance metrics

**Installation:**
```bash
# Ubuntu/Debian
sudo apt-get install curl jq bc

# CentOS/RHEL
sudo yum install curl jq bc

# macOS
brew install curl jq bc
```

## Test Features

### ✅ Comprehensive Coverage
- All API v1 endpoints (`/users`, `/zones`, `/zones/{id}/records`)
- Success and failure scenarios
- Authentication and authorization testing
- Input validation and edge cases
- Security vulnerability testing

### ✅ Environment-Based Configuration
- API key from environment variables
- Configurable base URL and timeouts
- Database connection for test data setup
- Optional HTTP Basic Auth support

### ✅ Robust Error Handling
- Network error detection
- HTTP status code validation
- JSON response validation
- Timeout handling
- Graceful failure recovery

### ✅ Performance Monitoring
- Response time measurement
- Concurrent request testing
- Rate limiting detection
- Memory leak testing
- Requests per second calculation

### ✅ Security Testing
- SQL injection prevention
- XSS attack prevention
- Large payload handling
- Invalid JSON detection
- Authorization bypass testing

### ✅ Test Data Management
- Automatic test data creation
- Cleanup after test completion
- Isolation between test runs
- Minimal data footprint

## Output and Reporting

### Color-Coded Results
- 🟢 **PASS** - Test passed successfully
- 🔴 **FAIL** - Test failed with error details
- 🟡 **SKIP** - Test skipped (e.g., missing dependencies)
- 🟡 **WARNING** - Test passed with warnings

### Performance Metrics
- Response times for individual requests
- Requests per second for load tests
- Success/failure rates
- Min/max/average response times

### Example Output
```
================================
Starting API Test Suite
================================

--- Authentication Tests ---
Testing: Valid API key authentication
✓ PASS: Valid API key authentication (200, 0.123s)
Testing: Valid JSON response with data field
✓ PASS: Valid JSON response with data field

--- User Management Tests ---
Testing: List all users
✓ PASS: List all users (200, 0.089s)
Testing: Users list response structure
✓ PASS: Users list response structure

...

================================
Test Results Summary
================================
Total Tests: 127
Passed: 125
Failed: 0
Skipped: 2
Success Rate: 98%

✓ All tests passed!
```

## Advanced Usage

### Custom Test Scenarios

You can extend the test scripts by adding custom functions:

```bash
# Add to api-test.sh
test_custom_scenario() {
    print_section "Custom Test Scenario"
    
    api_request "GET" "/custom-endpoint" "" "200" "Custom test"
    validate_json_response "Custom response validation" "data,custom_field"
}
```

### Integration with CI/CD

#### GitHub Actions Example
```yaml
name: API Tests
on: [push, pull_request]

jobs:
  api-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      
      - name: Install dependencies
        run: sudo apt-get install curl jq bc
      
      - name: Setup test configuration
        run: |
          cd tests/api
          cp .env.api-test.example .env.api-test
          sed -i "s|API_KEY=.*|API_KEY=${{ secrets.TEST_API_KEY }}|" .env.api-test
          
      - name: Run API tests
        run: |
          cd tests/api
          ./run-tests.sh test all
```

#### Jenkins Pipeline
```groovy
pipeline {
    agent any
    stages {
        stage('API Tests') {
            steps {
                sh '''
                    cd tests/api
                    cp .env.api-test.example .env.api-test
                    sed -i "s|API_KEY=.*|API_KEY=${TEST_API_KEY}|" .env.api-test
                    ./run-tests.sh test all
                '''
            }
        }
    }
}
```

### Docker Integration

Run tests in a Docker container:

```dockerfile
FROM ubuntu:22.04

RUN apt-get update && apt-get install -y curl jq bc

COPY tests/api /tests
WORKDIR /tests

CMD ["./run-tests.sh", "test", "all"]
```

## Troubleshooting

### Common Issues

#### 1. Connection Refused
```
ERROR: API connection failed (check URL)
```
**Solution:** Verify `API_BASE_URL` is correct and Poweradmin is running.

#### 2. Authentication Failed
```
✗ API authentication failed (check API key)
```
**Solution:** Verify `API_KEY` is correct and not expired.

#### 3. Missing Dependencies
```
Missing dependencies: jq bc
```
**Solution:** Install required packages:
```bash
sudo apt-get install jq bc  # Ubuntu/Debian
brew install jq bc          # macOS
```

#### 4. Test Data Conflicts
```
FAIL: Create test zone - Expected 201, got 409
```
**Solution:** Clean up test data:
```bash
./run-tests.sh clean
```

### Debug Mode

Enable verbose output for debugging:

```bash
# Set debug mode
export DEBUG=1

# Run tests with maximum verbosity
./api-test.sh auth 2>&1 | tee debug.log
```

### Performance Tuning

Adjust load test parameters for your environment:

```bash
# In .env.api-test
LOAD_TEST_CONCURRENT=5      # Reduce concurrent requests
LOAD_TEST_TOTAL=50          # Reduce total requests
TEST_TIMEOUT=60             # Increase timeout for slow networks
```

## Comparison with PHPUnit Tests

| Feature | Shell/curl Tests | PHPUnit Tests |
|---------|------------------|---------------|
| **Dependencies** | curl, jq, bc | PHP, Guzzle, PHPUnit |
| **Setup** | Copy config file | Composer install |
| **Execution** | Native shell | PHP runtime |
| **Performance** | Lightweight | More overhead |
| **Debugging** | Shell-friendly | PHP debugging tools |
| **CI/CD** | Simple integration | Requires PHP environment |
| **Maintenance** | Shell scripting | PHP knowledge |

## Security Considerations

### Test Environment Only
- Never run against production systems
- Use dedicated test API keys
- Use isolated test databases
- Clean up test data after completion

### Sensitive Data
- Store API keys in environment variables
- Don't commit credentials to version control
- Use test-specific credentials with limited permissions
- Rotate test API keys regularly

This shell-based test suite provides a comprehensive, dependency-light alternative for API testing that can be easily integrated into any development workflow.