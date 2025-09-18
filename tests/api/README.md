# Poweradmin API Test Suite

Lightweight shell-based testing for all Poweradmin API v1 endpoints using curl and jq.

## Quick Start

```bash
# Setup
cp .env.api-test.example .env.api-test
# Edit .env.api-test with your API_KEY and database settings

# Run all tests (98 tests)
./api-test.sh

# Run dynamic DNS tests
./dynamic_dns_api_setup.sh
```

## Test Scripts

- **`api-test.sh`** - Main test suite (98 tests covering all API v1 endpoints)
- **`dynamic_dns_api_setup.sh`** - Dynamic DNS functionality tests
- **`run-tests.sh`** - Optional test runner wrapper

### Test Categories
- Authentication and authorization
- User/zone/record CRUD operations
- Input validation and error handling
- Security testing (XSS, invalid JSON)
- Performance measurement


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

**98 Tests** covering:
- All API v1 endpoints (`/users`, `/zones`, `/records`, `/permissions`)
- CRUD operations with success/failure scenarios
- Authentication, authorization, and security testing
- Input validation and performance measurement

**Output Example:**
```
================================
Starting API Test Suite
================================
Total Tests: 98
Passed: 98
Failed: 0
Success Rate: 100%

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