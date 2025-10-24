#!/bin/bash
# Verify LDAP Test Setup
# This script checks if LDAP test users are properly configured

set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "========================================="
echo "  LDAP Test Setup Verification"
echo "========================================="
echo ""

# Check if containers are running
echo -n "Checking LDAP container... "
if docker ps | grep -q "ldap"; then
    echo -e "${GREEN}✓ Running${NC}"
else
    echo -e "${RED}✗ Not running${NC}"
    exit 1
fi

echo -n "Checking MariaDB container... "
if docker ps | grep -q "mariadb"; then
    echo -e "${GREEN}✓ Running${NC}"
else
    echo -e "${RED}✗ Not running${NC}"
    exit 1
fi

echo ""
echo "Checking LDAP users..."
echo "-------------------------------------"

# Check testuser in LDAP
echo -n "testuser in LDAP... "
if docker exec ldap ldapsearch -x -H ldap://localhost \
    -b ou=users,dc=poweradmin,dc=org \
    -D 'cn=admin,dc=poweradmin,dc=org' \
    -w poweradmin '(uid=testuser)' uid 2>/dev/null | grep -q "uid: testuser"; then
    echo -e "${GREEN}✓ Found${NC}"
else
    echo -e "${RED}✗ Not found${NC}"
    exit 1
fi

# Check testuser2 in LDAP
echo -n "testuser2 in LDAP... "
if docker exec ldap ldapsearch -x -H ldap://localhost \
    -b ou=users,dc=poweradmin,dc=org \
    -D 'cn=admin,dc=poweradmin,dc=org' \
    -w poweradmin '(uid=testuser2)' uid 2>/dev/null | grep -q "uid: testuser2"; then
    echo -e "${GREEN}✓ Found${NC}"
else
    echo -e "${RED}✗ Not found${NC}"
    exit 1
fi

echo ""
echo "Checking Poweradmin database users..."
echo "-------------------------------------"

# Check testuser in database
echo -n "testuser in database... "
if docker exec mariadb mysql -u pdns -ppoweradmin poweradmin \
    -e "SELECT username, use_ldap FROM users WHERE username='testuser'" 2>/dev/null | grep -q "testuser"; then
    echo -e "${GREEN}✓ Found${NC}"
else
    echo -e "${RED}✗ Not found${NC}"
    exit 1
fi

# Check testuser2 in database
echo -n "testuser2 in database... "
if docker exec mariadb mysql -u pdns -ppoweradmin poweradmin \
    -e "SELECT username, use_ldap FROM users WHERE username='testuser2'" 2>/dev/null | grep -q "testuser2"; then
    echo -e "${GREEN}✓ Found${NC}"
else
    echo -e "${RED}✗ Not found${NC}"
    exit 1
fi

echo ""
echo "Testing LDAP authentication..."
echo "-------------------------------------"

# Test LDAP bind for testuser
echo -n "testuser LDAP bind... "
if docker exec ldap ldapwhoami -x -H ldap://localhost \
    -D 'uid=testuser,ou=users,dc=poweradmin,dc=org' \
    -w testpass123 2>/dev/null | grep -q "dn:uid=testuser"; then
    echo -e "${GREEN}✓ Success${NC}"
else
    echo -e "${RED}✗ Failed${NC}"
    exit 1
fi

# Test LDAP bind for testuser2
echo -n "testuser2 LDAP bind... "
if docker exec ldap ldapwhoami -x -H ldap://localhost \
    -D 'uid=testuser2,ou=users,dc=poweradmin,dc=org' \
    -w testpass456 2>/dev/null | grep -q "dn:uid=testuser2"; then
    echo -e "${GREEN}✓ Success${NC}"
else
    echo -e "${RED}✗ Failed${NC}"
    exit 1
fi

echo ""
echo "========================================="
echo -e "${GREEN}✓ All checks passed!${NC}"
echo "========================================="
echo ""
echo "Test credentials:"
echo "  User 1: testuser / testpass123"
echo "  User 2: testuser2 / testpass456"
echo ""
echo "Access points:"
echo "  Poweradmin: http://localhost:3000"
echo "  Adminer: http://localhost:8090"
echo "  phpLDAPadmin: https://localhost:8443"
echo ""
echo "Ready for LDAP session cache testing!"
