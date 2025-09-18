#!/bin/bash

# Simple Dynamic DNS Test Setup Script
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="$SCRIPT_DIR/.env.api-test"

# Check for help
if [[ "${1:-}" == "help" || "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    cat << EOF
Dynamic DNS Test Setup Script

Usage: $0 [COMMAND]

Commands:
  (no command)       Run full setup process (cleanup + create infrastructure)
  cleanup           Clean up existing test data only
  help              Show this help message

Examples:
  $0                # Full setup: cleanup + create user/zone/records
  $0 cleanup        # Only cleanup existing test data

This script manages Dynamic DNS test infrastructure:
- Creates/removes test user with Dynamic DNS permissions
- Creates/removes test zone (example.com)
- Creates/removes test DNS records
- Tests Dynamic DNS functionality

Configuration is read from: .env.api-test

EOF
    exit 0
fi

# Check if cleanup mode
CLEANUP_ONLY="${1:-}"

if [[ "$CLEANUP_ONLY" == "cleanup" ]]; then
    echo "=== Dynamic DNS Test Cleanup ==="
else
    echo "=== Dynamic DNS Test Setup ==="
fi

# Load config
source "$CONFIG_FILE"

USERNAME="${DYNAMIC_DNS_USER:-ddns_user}"
PASSWORD="${DYNAMIC_DNS_PASS:-ddns_password}"
HOSTNAME="${DYNAMIC_DNS_HOSTNAME:-test.example.com}"

echo "API URL: $API_BASE_URL"
echo "Username: $USERNAME"
echo "Hostname: $HOSTNAME"
echo ""

# 1. Clean up existing test data
echo "1. Cleaning up existing test data..."

# Delete test zone if it exists
echo "  Checking for existing test zone..."
EXISTING_ZONE=$(curl -s -H "X-API-Key: $API_KEY" "$API_BASE_URL/api/v1/zones" | \
    jq -r '.data[] | select(.name == "example.com") | .id')

if [[ -n "$EXISTING_ZONE" && "$EXISTING_ZONE" != "null" ]]; then
    echo "  Deleting existing zone ID: $EXISTING_ZONE"
    DELETE_ZONE_RESULT=$(curl -s -w "%{http_code}" -X DELETE -H "X-API-Key: $API_KEY" "$API_BASE_URL/api/v1/zones/$EXISTING_ZONE")
    DELETE_ZONE_CODE="${DELETE_ZONE_RESULT: -3}"
    if [[ "$DELETE_ZONE_CODE" == "200" || "$DELETE_ZONE_CODE" == "204" ]]; then
        echo "  ✓ Zone deleted successfully"
    else
        echo "  ⚠ Zone deletion failed (HTTP $DELETE_ZONE_CODE)"
    fi
fi

# Delete test user if it exists
echo "  Checking for existing test user..."
EXISTING_USER_ID=$(curl -s -H "X-API-Key: $API_KEY" "$API_BASE_URL/api/v1/users" | \
    jq -r --arg user "$USERNAME" '.data[] | select(.username == $user) | .user_id // .id')

if [[ -n "$EXISTING_USER_ID" && "$EXISTING_USER_ID" != "null" ]]; then
    echo "  Deleting existing user ID: $EXISTING_USER_ID"
    DELETE_USER_RESULT=$(curl -s -w "%{http_code}" -X DELETE -H "X-API-Key: $API_KEY" "$API_BASE_URL/api/v1/users/$EXISTING_USER_ID")
    DELETE_USER_CODE="${DELETE_USER_RESULT: -3}"
    if [[ "$DELETE_USER_CODE" == "200" || "$DELETE_USER_CODE" == "204" ]]; then
        echo "  ✓ User deleted successfully"
    else
        echo "  ⚠ User deletion failed (HTTP $DELETE_USER_CODE)"
    fi
fi

# Delete test permission templates if they exist
echo "  Checking for existing Dynamic DNS permission templates..."
EXISTING_TEMPLATES=$(curl -s -H "X-API-Key: $API_KEY" "$API_BASE_URL/api/v1/permission-templates" | \
    jq -r '.data[] | select(.name == "Dynamic DNS User") | .id')
if [[ -n "$EXISTING_TEMPLATES" ]]; then
    while IFS= read -r template_id; do
        if [[ -n "$template_id" && "$template_id" != "null" ]]; then
            echo "  Deleting existing permission template ID: $template_id"
            DELETE_TEMPLATE_RESULT=$(curl -s -w "%{http_code}" -X DELETE -H "X-API-Key: $API_KEY" "$API_BASE_URL/api/v1/permission-templates/$template_id")
            DELETE_TEMPLATE_CODE="${DELETE_TEMPLATE_RESULT: -3}"
            if [[ "$DELETE_TEMPLATE_CODE" == "200" || "$DELETE_TEMPLATE_CODE" == "204" ]]; then
                echo "  ✓ Permission template deleted successfully"
            else
                echo "  ⚠ Permission template deletion failed (HTTP $DELETE_TEMPLATE_CODE)"
            fi
        fi
    done <<< "$EXISTING_TEMPLATES"
fi

echo "  ✓ Cleanup completed"

# Exit if cleanup mode
if [[ "$CLEANUP_ONLY" == "cleanup" ]]; then
    echo ""
    echo "=== Cleanup Complete ==="
    echo "All Dynamic DNS test data has been removed."
    exit 0
fi

# 2. Create Dynamic DNS permission template
echo "2. Creating Dynamic DNS permission template..."
TEMPLATE_DATA="{
    \"name\": \"Dynamic DNS User\",
    \"descr\": \"Permission template for Dynamic DNS users with zone editing rights\",
    \"permissions\": [44, 62]
}"

TEMPLATE_RESPONSE=$(curl -s -w "%{http_code}" -X POST \
    -H "X-API-Key: $API_KEY" \
    -H "Content-Type: application/json" \
    -d "$TEMPLATE_DATA" \
    "$API_BASE_URL/api/v1/permission-templates")
TEMPLATE_HTTP_CODE="${TEMPLATE_RESPONSE: -3}"
TEMPLATE_BODY="${TEMPLATE_RESPONSE%???}"

if [[ "$TEMPLATE_HTTP_CODE" == "201" ]]; then
    echo "  ✓ Created Dynamic DNS permission template"
    # Get the newly created template ID
    DDNS_TEMPLATE_ID=$(curl -s -H "X-API-Key: $API_KEY" "$API_BASE_URL/api/v1/permission-templates" | \
        jq -r '.data[] | select(.name == "Dynamic DNS User") | .id' | tail -1)
elif [[ "$TEMPLATE_HTTP_CODE" == "409" ]]; then
    echo "  Template already exists, finding existing template..."
    EXISTING_TEMPLATE=$(curl -s -H "X-API-Key: $API_KEY" "$API_BASE_URL/api/v1/permission-templates" | \
        jq -r '.data[] | select(.name == "Dynamic DNS User") | .id')
    if [[ -n "$EXISTING_TEMPLATE" && "$EXISTING_TEMPLATE" != "null" ]]; then
        DDNS_TEMPLATE_ID="$EXISTING_TEMPLATE"
        echo "  ✓ Using existing template ID: $DDNS_TEMPLATE_ID"
    else
        echo "  ⚠ Using default template ID 1"
        DDNS_TEMPLATE_ID=1
    fi
else
    echo "  ⚠ Template creation failed (HTTP $TEMPLATE_HTTP_CODE): $TEMPLATE_BODY"
    echo "  Using default template ID 1"
    DDNS_TEMPLATE_ID=1
fi

# 3. Create user
echo "3. Creating Dynamic DNS user..."
USER_DATA="{
    \"username\": \"$USERNAME\",
    \"password\": \"$PASSWORD\",
    \"fullname\": \"Dynamic DNS Test User\",
    \"email\": \"ddns_test@example.com\",
    \"description\": \"User for Dynamic DNS testing\",
    \"perm_templ\": $DDNS_TEMPLATE_ID,
    \"active\": true
}"

USER_RESPONSE=$(curl -s -w "%{http_code}" -X POST \
    -H "X-API-Key: $API_KEY" \
    -H "Content-Type: application/json" \
    -d "$USER_DATA" \
    "$API_BASE_URL/api/v1/users")
USER_HTTP_CODE="${USER_RESPONSE: -3}"
USER_BODY="${USER_RESPONSE%???}"

if [[ "$USER_HTTP_CODE" == "201" ]]; then
    USER_ID=$(echo "$USER_BODY" | jq -r '.data.user_id // .data.id')
    echo "  ✓ Created user '$USERNAME' with ID: $USER_ID"

    # Assign Dynamic DNS permission template if it was created and is different from what was used
    if [[ "$DDNS_TEMPLATE_ID" != "1" ]]; then
        echo "  Assigning Dynamic DNS permission template to user..."
        ASSIGN_DATA="{\"perm_templ\": $DDNS_TEMPLATE_ID}"
        ASSIGN_RESPONSE=$(curl -s -w "%{http_code}" -X PATCH \
            -H "X-API-Key: $API_KEY" \
            -H "Content-Type: application/json" \
            -d "$ASSIGN_DATA" \
            "$API_BASE_URL/api/v1/users/$USER_ID")

        ASSIGN_HTTP_CODE="${ASSIGN_RESPONSE: -3}"
        if [[ "$ASSIGN_HTTP_CODE" == "200" ]]; then
            echo "  ✓ Assigned Dynamic DNS permission template"
        else
            echo "  ⚠ Permission template assignment failed, but user created with template $DDNS_TEMPLATE_ID"
        fi
    fi
else
    echo "  ✗ User creation failed (HTTP $USER_HTTP_CODE): $USER_BODY"
    exit 1
fi

# 4. Create zone
echo "4. Creating test zone..."
ZONE_DATA="{
    \"name\": \"example.com\",
    \"type\": \"NATIVE\",
    \"master\": \"\",
    \"account\": \"\",
    \"owner_user_id\": $USER_ID
}"

ZONE_RESPONSE=$(curl -s -w "%{http_code}" -X POST \
    -H "X-API-Key: $API_KEY" \
    -H "Content-Type: application/json" \
    -d "$ZONE_DATA" \
    "$API_BASE_URL/api/v1/zones")
ZONE_HTTP_CODE="${ZONE_RESPONSE: -3}"
ZONE_BODY="${ZONE_RESPONSE%???}"

if [[ "$ZONE_HTTP_CODE" == "201" ]]; then
    ZONE_ID=$(echo "$ZONE_BODY" | jq -r '.data.zone_id // .data.id')
    echo "  ✓ Created zone 'example.com' with ID: $ZONE_ID"
elif [[ "$ZONE_HTTP_CODE" == "409" ]]; then
    echo "  Zone already exists, finding existing zone..."
    EXISTING_ZONE=$(curl -s -X GET -H "X-API-Key: $API_KEY" "$API_BASE_URL/api/v1/zones" | \
        jq -r '.data[] | select(.name == "example.com") | .id')
    if [[ -n "$EXISTING_ZONE" && "$EXISTING_ZONE" != "null" ]]; then
        ZONE_ID="$EXISTING_ZONE"
        echo "  ✓ Using existing zone ID: $ZONE_ID"
    else
        echo "  ✗ Failed to find existing zone"
        exit 1
    fi
else
    echo "  ✗ Zone creation failed (HTTP $ZONE_HTTP_CODE): $ZONE_BODY"
    exit 1
fi

# 5. Create test record
echo "5. Creating test DNS record..."
RECORD_DATA="{
    \"name\": \"$HOSTNAME\",
    \"type\": \"A\",
    \"content\": \"192.168.1.100\",
    \"ttl\": 300,
    \"disabled\": false
}"

RECORD_RESPONSE=$(curl -s -w "%{http_code}" -X POST \
    -H "X-API-Key: $API_KEY" \
    -H "Content-Type: application/json" \
    -d "$RECORD_DATA" \
    "$API_BASE_URL/api/v1/zones/$ZONE_ID/records")
RECORD_HTTP_CODE="${RECORD_RESPONSE: -3}"
RECORD_BODY="${RECORD_RESPONSE%???}"

if [[ "$RECORD_HTTP_CODE" == "201" ]]; then
    RECORD_ID=$(echo "$RECORD_BODY" | jq -r '.data.record_id // .data.id')
    echo "  ✓ Created A record for '$HOSTNAME' with ID: $RECORD_ID"
else
    echo "  ⚠ Record creation failed (HTTP $RECORD_HTTP_CODE): $RECORD_BODY"
    echo "  This is not critical - Dynamic DNS will create records as needed"
    RECORD_ID="N/A"
fi

# 6. Test Dynamic DNS
echo "6. Testing Dynamic DNS..."
DDNS_RESPONSE=$(curl -s -H "User-Agent: SetupScript/1.0" \
    -u "$USERNAME:$PASSWORD" \
    "$API_BASE_URL/dynamic_update.php?hostname=$HOSTNAME&myip=192.168.1.200&verbose=1")

echo "  Dynamic DNS Response: $DDNS_RESPONSE"

if [[ "$DDNS_RESPONSE" == *"hostname has been updated"* ]]; then
    echo "  ✓ Dynamic DNS test SUCCESSFUL!"
elif [[ "$DDNS_RESPONSE" == *"!yours"* ]]; then
    echo "  ⚠ Authentication works but zone ownership issue"
else
    echo "  ✗ Dynamic DNS test failed"
fi

echo ""
echo "=== Setup Complete ==="
echo "User ID: $USER_ID"
echo "Zone ID: $ZONE_ID"
echo "Record ID: $RECORD_ID"
echo ""
echo "You can now run: ./dynamic_dns_api_test.sh"
