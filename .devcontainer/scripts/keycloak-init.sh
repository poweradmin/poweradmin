#!/bin/bash

# Wait for Keycloak to be ready
echo "Waiting for Keycloak to start..."
sleep 30

# Set Keycloak admin CLI path
KC_CLI="/opt/keycloak/bin/kcadm.sh"

# Login to admin
$KC_CLI config credentials --server http://localhost:8080 --realm master --user admin --password admin

# Create PowerAdmin realm
$KC_CLI create realms -s realm=poweradmin -s enabled=true

# Create PowerAdmin client
CLIENT_ID=$($KC_CLI create clients -r poweradmin -s clientId=poweradmin -s enabled=true -s clientAuthenticatorType=client-secret -s directAccessGrantsEnabled=true -s standardFlowEnabled=true -s implicitFlowEnabled=false -s serviceAccountsEnabled=false -s 'redirectUris=["http://localhost:3000/oidc/callback","http://localhost:3001/oidc/callback","http://localhost:3002/oidc/callback"]' -s 'webOrigins=["http://localhost:3000","http://localhost:3001","http://localhost:3002"]' --id)

# Get client secret
CLIENT_SECRET=$($KC_CLI get clients/$CLIENT_ID/client-secret -r poweradmin --fields value --format csv --noquotes)

# Create groups
$KC_CLI create groups -r poweradmin -s name=poweradmin-admins
$KC_CLI create groups -r poweradmin -s name=dns-operators
$KC_CLI create groups -r poweradmin -s name=dns-viewers

# Create test users
USER1_ID=$($KC_CLI create users -r poweradmin -s username=testadmin -s enabled=true -s email=testadmin@example.com -s firstName=Test -s lastName=Admin --id)
USER2_ID=$($KC_CLI create users -r poweradmin -s username=testuser -s enabled=true -s email=testuser@example.com -s firstName=Test -s lastName=User --id)

# Set passwords
$KC_CLI set-password -r poweradmin --username testadmin --new-password password
$KC_CLI set-password -r poweradmin --username testuser --new-password password

# Add users to groups
ADMIN_GROUP_ID=$($KC_CLI get groups -r poweradmin -q name=poweradmin-admins --fields id --format csv --noquotes)
VIEWER_GROUP_ID=$($KC_CLI get groups -r poweradmin -q name=dns-viewers --fields id --format csv --noquotes)

$KC_CLI update users/$USER1_ID/groups/$ADMIN_GROUP_ID -r poweradmin
$KC_CLI update users/$USER2_ID/groups/$VIEWER_GROUP_ID -r poweradmin

# Create group mapper for client
$KC_CLI create clients/$CLIENT_ID/protocol-mappers/models -r poweradmin -s name=groups -s protocol=openid-connect -s protocolMapper=oidc-group-membership-mapper -s 'config."full.path"=false' -s 'config."id.token.claim"=true' -s 'config."access.token.claim"=true' -s 'config."claim.name"=groups' -s 'config."userinfo.token.claim"=true'

echo "================================"
echo "Keycloak PowerAdmin realm setup complete!"
echo "================================"
echo "Admin Console: http://localhost:8080"
echo "Admin User: admin / admin"
echo ""
echo "PowerAdmin Realm: poweradmin"
echo "Client ID: poweradmin"
echo "Client Secret: $CLIENT_SECRET"
echo ""
echo "Test Users:"
echo "  testadmin / password (poweradmin-admins group)"
echo "  testuser / password (dns-viewers group)"
echo ""
echo "Groups:"
echo "  poweradmin-admins"
echo "  dns-operators"
echo "  dns-viewers"
echo "================================"