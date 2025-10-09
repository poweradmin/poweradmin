#!/bin/bash
set -e

echo "Setting up LDAP test environment..."

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

# Add corresponding database user
echo ""
echo "Adding database user..."
if docker exec mariadb mysql -uroot -puberuser pdns -e "
INSERT IGNORE INTO users (username, password, fullname, email, description, active, use_ldap)
VALUES ('testuser', '', 'Test User', 'testuser@poweradmin.org', 'LDAP Test User', 1, 1);
" 2>/dev/null; then
    echo "✓ Database user created successfully"
else
    echo "⚠ Database user might already exist or failed to create"
fi

echo ""
echo "==================================="
echo "LDAP Test Environment Ready!"
echo "==================================="
echo ""
echo "Test credentials:"
echo "  Username: testuser"
echo "  Password: testpass123"
echo ""
echo "LDAP Admin (phpLDAPadmin): https://localhost:8443"
echo "  Login DN: cn=admin,dc=poweradmin,dc=org"
echo "  Password: poweradmin"
echo ""
