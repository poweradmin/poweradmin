# API Test Configuration - PostgreSQL (devcontainer)
# Web server: apache on port 8081

# API Configuration
API_BASE_URL=http://localhost:8081
API_KEY=test-api-key-for-automated-testing-12345

# Database Configuration (PostgreSQL devcontainer)
DB_HOST=localhost
DB_NAME=pdns
DB_USER=pdns
DB_PASS=poweradmin
DB_TYPE=pgsql

# Test Behavior
TEST_TIMEOUT=30
TEST_CLEANUP=true

# Load Testing
LOAD_TEST_CONCURRENT=10
LOAD_TEST_TOTAL=100
LOAD_TEST_DURATION=60
LOAD_TEST_RAMP_UP=10
