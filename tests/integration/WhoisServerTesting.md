# WHOIS Server Testing

This document describes the tools and tests available for validating the WHOIS server data used by Poweradmin.

## Available Tests and Tools

### 1. Integration Tests

Two integration tests have been created to verify WHOIS server functionality:

#### WhoisServerAvailabilityTest

This test checks the availability of WHOIS servers by attempting to connect to them:

- `testSampleWhoisServerAvailability`: Tests a representative sample of WHOIS servers (faster)
- `testAllWhoisServersAvailability`: Tests all WHOIS servers (slower, skipped by default)

Run these tests with:

```bash
# Run the sample test (faster)
vendor/bin/phpunit tests/integration/WhoisServerAvailabilityTest.php

# Run the comprehensive test (slower)
vendor/bin/phpunit --filter testAllWhoisServersAvailability tests/integration/WhoisServerAvailabilityTest.php
```

#### WhoisServiceIntegrationTest

Tests the WhoisService functionality including:

- Loading the WHOIS servers list
- Performing basic WHOIS queries
- Server lookup for domains
- Complete workflow testing
- Error handling

Run with:

```bash
vendor/bin/phpunit tests/integration/WhoisServiceIntegrationTest.php
```

### 2. WHOIS Server Checking Script

A standalone script is available to check all WHOIS servers and generate a detailed report:

```bash
# Run with default settings (text output)
php scripts/check_whois_servers.php

# Get JSON output
php scripts/check_whois_servers.php --output=json

# Get CSV output
php scripts/check_whois_servers.php --output=csv

# Adjust timeout (in seconds)
php scripts/check_whois_servers.php --timeout=5
```

The script provides:
- Summary statistics on server availability
- List of unavailable servers with error details
- Top 10 fastest responding servers
- Output in text, JSON, or CSV format

## When to Run These Tests

- **During development**: When making changes to the WhoisService or updating the WHOIS servers list
- **Periodically**: To verify that the WHOIS servers are still available and reliable
- **Before releases**: To ensure that the WHOIS functionality is working correctly

## Maintaining the WHOIS Servers List

The WHOIS servers list is stored in `data/whois_servers.json`. To update this list:

1. Run the check script to identify unavailable servers
2. Research updated server information for any unavailable servers
3. Update the JSON file with the new information
4. Run the integration tests to verify the changes

## Notes on Test Reliability

WHOIS server availability tests may occasionally fail due to:
- Network connectivity issues
- Rate limiting by WHOIS servers
- Temporary server outages

The tests are designed to be tolerant of some failures, requiring only 70% of servers to be available to pass.