#!/bin/bash
set -e

echo "Setting up LDAP test environment..."

# Determine script directory and repo root (for host path resolution)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Wait for LDAP service to be ready
echo "Waiting for LDAP service..."
timeout=30
counter=0
until docker exec ldap ldapsearch -x -H ldap://localhost -b dc=poweradmin,dc=org -D 'cn=admin,dc=poweradmin,dc=org' -w poweradmin >/dev/null 2>&1; do
    sleep 1
    counter=$((counter + 1))
    if [ $counter -ge $timeout ]; then
        echo "✗ LDAP service failed to start within ${timeout} seconds"
        exit 1
    fi
done
echo "✓ LDAP service is ready"

# Wait for MariaDB service to be ready
echo "Waiting for MariaDB service..."
counter=0
until docker exec mariadb mysqladmin ping -h localhost -uroot -puberuser >/dev/null 2>&1; do
    sleep 1
    counter=$((counter + 1))
    if [ $counter -ge $timeout ]; then
        echo "✗ MariaDB service failed to start within ${timeout} seconds"
        exit 1
    fi
done
echo "✓ MariaDB service is ready"

# Add LDAP test user
echo ""
echo "Adding LDAP test user..."
if docker exec ldap ldapadd -x -D "cn=admin,dc=poweradmin,dc=org" -w poweradmin -f /ldap-test-user.ldif 2>/dev/null; then
    echo "✓ LDAP user created successfully"
else
    echo "⚠ LDAP user might already exist or failed to create"
fi

# Add corresponding database users (testuser + testuser2)
echo ""
echo "Adding database users..."
# Use host path for input redirection (resolved before docker exec runs)
SQL_FILE="$REPO_ROOT/.devcontainer/sql/add-ldap-test-users.sql"
if [ -f "$SQL_FILE" ]; then
    if docker exec -i mariadb mysql -uroot -puberuser poweradmin < "$SQL_FILE" >/dev/null 2>&1; then
        echo "✓ Database users created successfully (testuser, testuser2)"
    else
        echo "⚠ Database users might already exist or failed to create"
    fi
else
    echo "⚠ SQL file not found: $SQL_FILE"
    echo "  Attempting fallback inline user creation..."
    # Fallback: Create users inline if SQL file not found
    docker exec mariadb mysql -uroot -puberuser poweradmin -e "
    INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method)
    SELECT 'testuser', '', 'Test User (LDAP)', 'testuser@poweradmin.org', 'LDAP test user', 1, 1, 1, 'ldap'
    WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'testuser');

    INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method)
    SELECT 'testuser2', '', 'Test User 2 (LDAP)', 'testuser2@poweradmin.org', 'LDAP test user 2', 2, 1, 1, 'ldap'
    WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'testuser2');
    " >/dev/null 2>&1 && echo "✓ Fallback user creation successful"
fi

echo ""
echo "==================================="
echo "LDAP Test Environment Ready!"
echo "==================================="
echo ""
echo "Test credentials:"
echo "  User 1: testuser / testpass123 (Administrator)"
echo "  User 2: testuser2 / testpass456 (Zone Manager)"
echo ""
echo "LDAP Admin (phpLDAPadmin): https://localhost:8443"
echo "  Login DN: cn=admin,dc=poweradmin,dc=org"
echo "  Password: poweradmin"
echo ""
echo "Verify setup: .devcontainer/scripts/verify-ldap-test-setup.sh"
echo ""
