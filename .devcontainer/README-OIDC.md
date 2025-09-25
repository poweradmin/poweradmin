# Keycloak OIDC Testing Setup

This guide explains how to test Keycloak OIDC integration with Poweradmin in the devcontainer environment.

## Quick Start

1. **Start the PostgreSQL and Keycloak services:**
   ```bash
   cd .devcontainer
   docker-compose up -d pgsql keycloak
   ```

2. **Wait for services to initialize** (2-3 minutes for first startup)

3. **Setup Keycloak database (if needed):**
   ```bash
   docker exec postgres psql -U pdns -d pdns -c "CREATE DATABASE keycloak; CREATE USER keycloak WITH ENCRYPTED PASSWORD 'keycloak'; GRANT ALL PRIVILEGES ON DATABASE keycloak TO keycloak; ALTER DATABASE keycloak OWNER TO keycloak;"
   ```

4. **Initialize Keycloak realm:**
   ```bash
   docker exec keycloak /opt/keycloak/bin/keycloak-init.sh
   ```

5. **Update the client secret in settings.php:**
   - Copy the client secret from the initialization output
   - Update `config/settings.php` in the keycloak provider configuration
   - Current secret: `kIU1gpTwtxkMNbKmaolWJsTeItDSBwpv`

6. **Start other services:**
   ```bash
   cd .devcontainer
   docker-compose up -d
   ```

7. **Access services:**
   - **Poweradmin:** http://localhost:3000 (Nginx), http://localhost:3001 (Apache), http://localhost:3002 (Caddy)
   - **Keycloak Admin:** http://localhost:8080 (admin/admin)
   - **Adminer:** http://localhost:8090

## Services Overview

### Keycloak Configuration
- **Container:** `keycloak`
- **Port:** `8080`
- **Admin:** admin/admin
- **Realm:** poweradmin
- **Client ID:** poweradmin
- **Database:** PostgreSQL (shared with PowerDNS)

### Test Users
Created automatically by the setup script:

| Username   | Password | Groups            | Purpose |
|------------|----------|-------------------|---------|
| testadmin  | password | poweradmin-admins | Admin testing |
| testuser   | password | dns-viewers       | Regular user testing |

### Groups and Permissions
- **poweradmin-admins** → Administrator permissions
- **dns-operators** → Administrator permissions (adjust as needed)
- **dns-viewers** → Administrator permissions (adjust as needed)

## Testing OIDC Authentication

1. **Access Poweradmin** at http://localhost:3000
2. **Click "Sign in with Keycloak"** button
3. **Login with test credentials:**
   - Username: `testadmin` or `testuser`
   - Password: `password`
4. **Verify user creation** in Poweradmin user management
5. **Test group mapping** and permissions

## Configuration Details

### Keycloak Realm Setup
The initialization script creates:
- PowerAdmin realm
- Client with proper redirect URIs
- User groups for role mapping
- Test users with group assignments
- Group mapper for claims

### Docker Network Configuration

**Important**: For proper OIDC functionality in Docker environments, Keycloak needs to be accessible from both:
- **Host browser** (for user authentication redirects)
- **Poweradmin container** (for token exchange and user info requests)

**Recommended approach**: Use your machine's network IP address instead of `localhost` or container hostnames.

#### Finding Your Network IP
```bash
# Linux
ip addr show | grep 192.168

# macOS
ifconfig | grep "inet 192.168"

# Windows
ipconfig | findstr 192.168
```

#### OIDC Provider Configuration
Located in `config/settings.php`:
```php
'keycloak' => [
    'name' => 'Keycloak',
    'display_name' => 'Sign in with Keycloak',
    'client_id' => 'poweradmin',
    'client_secret' => '', // Set from Keycloak setup
    'base_url' => 'http://192.168.1.100:8080', // Use your network IP
    'realm' => 'poweradmin',
    'auto_discovery' => true,
    'metadata_url' => 'http://192.168.1.100:8080/realms/poweradmin/.well-known/openid-configuration',
    'scopes' => 'openid profile email groups',
    // ... user mapping configuration
]
```

**Why network IP works:**
- Browser can access Keycloak for authentication
- Docker containers can reach Keycloak for API calls
- Consistent URL across all OIDC endpoints

### Permission Template Mapping
```php
'permission_template_mapping' => [
    'poweradmin-admins' => 'Administrator',
    'dns-operators' => 'Administrator',
    'dns-viewers' => 'Administrator',
],
```

## Debugging

### Check Keycloak Status
```bash
# View Keycloak logs
docker logs keycloak

# Access Keycloak admin console
open http://localhost:8080
```

### Check OIDC Debug Logs
Poweradmin logs OIDC authentication details when debug logging is enabled:
```php
// In config/settings.php
'logging' => [
    'level' => 'debug',
],
```

### Common Issues

1. **Client secret empty:**
   - Run: `docker exec -it keycloak /opt/keycloak/bin/keycloak-init.sh`
   - Copy the displayed client secret to settings.php

2. **Keycloak not accessible:**
   - Ensure PostgreSQL is healthy: `docker ps`
   - Check Keycloak logs: `docker logs keycloak`

3. **OIDC authentication fails:**
   - Verify redirect URIs in Keycloak client settings
   - Check Poweradmin logs for detailed error messages
   - Ensure groups are properly mapped in user claims
   - For Docker networking issues, see "Docker Network Configuration" section above

### Manual Keycloak Configuration

If automatic setup fails, configure manually:

1. **Access Keycloak admin:** http://localhost:8080 (admin/admin)
2. **Create realm:** "poweradmin"
3. **Create client:**
   - Client ID: "poweradmin"
   - Client Protocol: "openid-connect"
   - Access Type: "confidential"
   - Valid Redirect URIs: `http://localhost:300*/oidc/callback`
4. **Create groups:** poweradmin-admins, dns-operators, dns-viewers
5. **Create users** and assign to groups
6. **Add group mapper** to client for claims

## Files Modified

- `.devcontainer/docker-compose.yml` - Added Keycloak service
- `.devcontainer/scripts/postgres-init-keycloak.sh` - PostgreSQL setup
- `.devcontainer/scripts/keycloak-init.sh` - Keycloak realm configuration
- `config/settings.php` - OIDC provider configuration

## Security Notes

- This setup is for development only
- Use proper secrets management in production
- Configure proper SSL/TLS for production Keycloak
- Review and adjust permission mappings based on your requirements