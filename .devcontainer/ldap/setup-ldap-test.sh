#!/bin/bash
# =============================================================================
# Setup LDAP Test Environment for Poweradmin 4.x
# =============================================================================
#
# Purpose: Load LDAP test users and verify the setup
#
# This script:
# - Waits for LDAP service to be ready
# - Loads test users from ldap-test-users.ldif
# - Verifies the users were created correctly
#
# Usage:
#   .devcontainer/ldap/setup-ldap-test.sh
#
# Prerequisites:
#   - Docker containers must be running (docker-compose up)
#   - LDAP container must be healthy
#   - ldap-utils package must be installed (ldapsearch, ldapadd)
#
# =============================================================================

set -e

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# LDAP connection settings (connect via Docker network)
LDAP_HOST="${LDAP_HOST:-ldap}"
LDAP_URI="ldap://${LDAP_HOST}"
LDAP_ADMIN_DN="cn=admin,dc=poweradmin,dc=org"
LDAP_ADMIN_PW="poweradmin"
LDAP_BASE_DN="dc=poweradmin,dc=org"

# Path to LDIF file (relative to /app in container)
LDIF_FILE="/app/.devcontainer/ldap/ldap-test-users.ldif"

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}  Poweradmin 4.x LDAP Test Setup${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""

# Check if ldapsearch is available
if ! command -v ldapsearch &> /dev/null; then
    echo -e "${RED}Error: ldapsearch not found. Please install ldap-utils package.${NC}"
    exit 1
fi

# Check if LDIF file exists
if [ ! -f "$LDIF_FILE" ]; then
    echo -e "${RED}Error: LDIF file not found at $LDIF_FILE${NC}"
    exit 1
fi

# Wait for LDAP service to be ready
echo -e "${YELLOW}Waiting for LDAP service at ${LDAP_URI}...${NC}"
timeout=60
counter=0
until ldapsearch -x -H "$LDAP_URI" -b "$LDAP_BASE_DN" -D "$LDAP_ADMIN_DN" -w "$LDAP_ADMIN_PW" >/dev/null 2>&1; do
    sleep 1
    counter=$((counter + 1))
    if [ $counter -ge $timeout ]; then
        echo -e "${RED}LDAP service failed to start within ${timeout} seconds${NC}"
        exit 1
    fi
    printf "."
done
echo ""
echo -e "${GREEN}LDAP service is ready${NC}"

# Check if users already exist
echo ""
echo -e "${YELLOW}Checking for existing LDAP users...${NC}"
if ldapsearch -x -H "$LDAP_URI" -b "ou=users,$LDAP_BASE_DN" -D "$LDAP_ADMIN_DN" -w "$LDAP_ADMIN_PW" "(uid=ldap-admin)" 2>/dev/null | grep -q "uid=ldap-admin"; then
    echo -e "${GREEN}LDAP test users already exist${NC}"
else
    # Add LDAP test users
    echo -e "${YELLOW}Adding LDAP test users...${NC}"
    if ldapadd -x -H "$LDAP_URI" -D "$LDAP_ADMIN_DN" -w "$LDAP_ADMIN_PW" -f "$LDIF_FILE" 2>/dev/null; then
        echo -e "${GREEN}LDAP test users created successfully${NC}"
    else
        # Try to determine if it's because they already exist
        if ldapsearch -x -H "$LDAP_URI" -b "ou=users,$LDAP_BASE_DN" -D "$LDAP_ADMIN_DN" -w "$LDAP_ADMIN_PW" "(uid=ldap-admin)" 2>/dev/null | grep -q "uid=ldap-admin"; then
            echo -e "${YELLOW}LDAP test users might already exist${NC}"
        else
            echo -e "${RED}Failed to create LDAP test users${NC}"
            exit 1
        fi
    fi
fi

# Verify users
echo ""
echo -e "${YELLOW}Verifying LDAP users...${NC}"
users_found=0
for user in ldap-admin ldap-manager ldap-client ldap-viewer; do
    if ldapsearch -x -H "$LDAP_URI" -b "ou=users,$LDAP_BASE_DN" -D "$LDAP_ADMIN_DN" -w "$LDAP_ADMIN_PW" "(uid=$user)" 2>/dev/null | grep -q "uid=$user"; then
        echo -e "  ${GREEN}$user${NC}"
        users_found=$((users_found + 1))
    else
        echo -e "  ${RED}$user (not found)${NC}"
    fi
done

echo ""
echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}  LDAP Test Environment Ready!${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""
echo -e "${GREEN}LDAP Test Users:${NC}"
echo "  Username      | Password     | Permissions"
echo "  --------------|--------------|------------------"
echo "  ldap-admin    | testpass123  | Administrator"
echo "  ldap-manager  | testpass123  | Zone Manager"
echo "  ldap-client   | testpass123  | Client Editor"
echo "  ldap-viewer   | testpass123  | Read Only"
echo ""
echo -e "${GREEN}LDAP Admin Access:${NC}"
echo "  phpLDAPadmin: https://localhost:8443"
echo "  Login DN:     $LDAP_ADMIN_DN"
echo "  Password:     $LDAP_ADMIN_PW"
echo ""
echo -e "${YELLOW}Note:${NC} Database users must also be imported via import-test-data.sh"
echo ""

if [ $users_found -eq 4 ]; then
    exit 0
else
    echo -e "${RED}Warning: Not all LDAP users were verified ($users_found/4)${NC}"
    exit 1
fi
