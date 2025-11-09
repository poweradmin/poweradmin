# Poweradmin API Test Suite

Comprehensive shell-based testing for Poweradmin API v1 and v2 endpoints using curl and jq.

## Quick Start

```bash
# Setup
cp .env.api-test.example .env.api-test
# Edit .env.api-test with your API_KEY and database settings

# Run all tests (v1 + v2)
./run-tests.sh test all

# Run API v1 tests only (98 tests)
./run-tests.sh test v1

# Run API v2 tests only
./run-tests.sh test v2

# Run specific v1 test suite
./run-tests.sh test v1:zones
```

## Test Scripts

- **`api-test.sh`** - API v1 test suite (98 tests covering all API v1 endpoints)
- **`api-v2-test.sh`** - API v2 test suite (RRSets, Bulk operations, PTR auto-creation, Master ports)
- **`run-tests.sh`** - Test runner with support for both v1 and v2
- **`dynamic_dns_api_setup.sh`** - Dynamic DNS functionality tests

### API v1 Test Categories
- Authentication and authorization
- User/zone/record CRUD operations
- Input validation and error handling
- Security testing (XSS, invalid JSON)
- Performance measurement

### API v2 Test Categories
- RRSet management (DNS-correct record sets)
- PTR auto-creation for forward records
- Bulk record operations (atomic transactions)
- Master server port specifications


## Configuration

Edit `.env.api-test` with your settings:
```bash
API_BASE_URL=http://localhost:8080
API_KEY=your-test-api-key-here
DB_HOST=localhost
DB_NAME=poweradmin_test
DB_USER=test_user
DB_PASS=test_password
DB_TYPE=mysql
```

### Dependencies
- `curl` and `jq` (install via `apt-get install curl jq` or `brew install curl jq`)

## Test Coverage

### API v1 Tests (98 tests)
Covers all API v1 endpoints:
- `/api/v1/users` - User management
- `/api/v1/zones` - Zone management (MASTER, SLAVE, NATIVE)
- `/api/v1/zones/{id}/records` - Record CRUD operations
- `/api/v1/permissions` - Permission management
- CRUD operations with success/failure scenarios
- Authentication, authorization, and security testing
- Input validation and performance measurement

### API v2 Tests
Covers new API v2 features:
- `/api/v2/zones/{id}/rrsets` - RRSet management (create, read, update, delete)
- `/api/v2/zones/{id}/records` - PTR auto-creation with `create_ptr` flag
- `/api/v2/zones/{id}/records/bulk` - Atomic bulk operations
- `/api/v2/zones` - Master server port syntax validation

**Output Example:**
```
═══════════════════════════════════════════
  API v1 Test Suite
═══════════════════════════════════════════
Total Tests: 98
Passed: 98
Failed: 0
Success Rate: 100%

═══════════════════════════════════════════
  API v2 Test Suite
═══════════════════════════════════════════
Total Tests: 48
Passed: 48
Failed: 0

✓ All tests passed!
```

## Troubleshooting

- **Connection failed**: Check `API_BASE_URL` and ensure Poweradmin is running
- **Authentication failed**: Verify `API_KEY` is correct and not expired
- **Missing dependencies**: Install `curl` and `jq`
- **Test conflicts**: Clean up with `./run-tests.sh clean`
- **Debug mode**: Set `export DEBUG=1` before running tests

## Security Notes

- Use dedicated test API keys and databases only
- Never run against production systems
- Clean up test data after completion