# Docker Deployment Guide

This guide covers deploying Poweradmin using Docker with FrankenPHP and various database configurations.

## Official Docker Images

Poweradmin official Docker images are available at:

- **Docker Hub**: `edmondas/poweradmin`
- **GitHub Container Registry**: `ghcr.io/poweradmin/poweradmin`

## Quick Start

```bash
# Basic deployment with SQLite (default)
docker run -d --name poweradmin -p 80:80 edmondas/poweradmin

# With external MySQL database
docker run -d --name poweradmin -p 80:80 \
  -e DB_TYPE=mysql \
  -e DB_HOST=mysql-server \
  -e DB_USER=poweradmin \
  -e DB_PASS=password \
  -e DB_NAME=poweradmin \
  edmondas/poweradmin
```

## Security with Docker Secrets

For production deployments, use Docker secrets to securely manage sensitive configuration values. See [DOCKER-SECRETS.md](DOCKER-SECRETS.md) for detailed information on:

- Using environment variables with `__FILE` suffix
- Docker Compose with secrets
- Docker Swarm deployment
- Security best practices

Example with secrets:
```bash
docker run -d --name poweradmin -p 80:80 \
  -e DB_TYPE=mysql \
  -e DB_HOST=mysql-server \
  -e DB_USER=poweradmin \
  -e DB_PASS__FILE=/run/secrets/db_password \
  -e DB_NAME=poweradmin \
  -v /path/to/secret:/run/secrets/db_password:ro \
  edmondas/poweradmin
```

## Architecture

Poweradmin Docker image is based on [FrankenPHP](https://frankenphp.dev/), a modern application server for PHP that provides:

- **High Performance**: Persistent worker mode for better performance than traditional PHP-FPM
- **Modern HTTP**: Native HTTP/2 and HTTP/3 support
- **Built-in Features**: Automatic HTTPS, real-time capabilities, and more
- **Caddy Integration**: Built on Caddy web server with powerful configuration

## Database Configuration

Poweradmin's Docker image supports multiple database types through environment variables:

### SQLite (Default)

The default configuration uses SQLite with no additional setup required:

```bash
docker run -d --name poweradmin -p 80:80 edmondas/poweradmin
```

### MySQL Configuration

To use MySQL as the database backend:

```bash
docker run -d --name poweradmin -p 80:80 \
  -e DB_TYPE=mysql \
  -e DB_HOST=mysql-server \
  -e DB_USER=poweradmin \
  -e DB_PASS=password \
  -e DB_NAME=poweradmin \
  -e DNS_NS1=ns1.yourdomain.com \
  -e DNS_NS2=ns2.yourdomain.com \
  -e DNS_HOSTMASTER=hostmaster.yourdomain.com \
  edmondas/poweradmin
```

### MySQL with Separate PowerDNS Database

To use separate databases for Poweradmin and PowerDNS data (**MySQL only**):

```bash
docker run -d --name poweradmin -p 80:80 \
  -e DB_TYPE=mysql \
  -e DB_HOST=mysql-server \
  -e DB_USER=poweradmin \
  -e DB_PASS=password \
  -e DB_NAME=poweradmin \
  -e PA_PDNS_DB_NAME=pdns \
  -e DNS_NS1=ns1.yourdomain.com \
  -e DNS_NS2=ns2.yourdomain.com \
  -e DNS_HOSTMASTER=hostmaster.yourdomain.com \
  edmondas/poweradmin
```

**Note**: The `PA_PDNS_DB_NAME` setting allows Poweradmin to connect to a separate MySQL database where PowerDNS stores its DNS records, while keeping Poweradmin's user and configuration data in its own database. This is useful when you have an existing PowerDNS installation with its own database.

**Important**: When using `PA_PDNS_DB_NAME`, both databases must be on the same MySQL server and accessible with the same credentials (`DB_HOST`, `DB_USER`, `DB_PASS`). Poweradmin will use the same connection details to access both the Poweradmin database (`DB_NAME`) for user management and the PowerDNS database (`PA_PDNS_DB_NAME`) for DNS records. The database user must have appropriate permissions on both databases.

### PostgreSQL Configuration

To use PostgreSQL as the database backend:

```bash
docker run -d --name poweradmin -p 80:80 \
  -e DB_TYPE=pgsql \
  -e DB_HOST=postgres-server \
  -e DB_USER=poweradmin \
  -e DB_PASS=password \
  -e DB_NAME=poweradmin \
  -e DNS_NS1=ns1.yourdomain.com \
  -e DNS_NS2=ns2.yourdomain.com \
  -e DNS_HOSTMASTER=hostmaster.yourdomain.com \
  edmondas/poweradmin
```

## Environment Variables

### Database Configuration

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `DB_TYPE` | Database type: `sqlite`, `mysql`, or `pgsql` | `sqlite` | No |
| `DB_HOST` | Database host (unused for SQLite) | Empty | Yes for MySQL/PostgreSQL |
| `DB_USER` | Database username (unused for SQLite) | Empty | Yes for MySQL/PostgreSQL |
| `DB_PASS` | Database password (unused for SQLite) | Empty | Yes for MySQL/PostgreSQL |
| `DB_NAME` | Database name (unused for SQLite) | Empty | Yes for MySQL/PostgreSQL |
| `DB_FILE` | SQLite database file path (unused for MySQL/PostgreSQL) | `/db/pdns.db` | No |
| `PA_PDNS_DB_NAME` | Separate PowerDNS database name (**MySQL only**) | Empty | No |
| `PDNS_VERSION` | PowerDNS schema version to use (45, 46, 47, 48, 49) | `49` | No |

### DNS Configuration

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `DNS_NS1` | Primary DNS nameserver | `ns1.example.com` | Yes |
| `DNS_NS2` | Secondary DNS nameserver | `ns2.example.com` | Yes |
| `DNS_NS3` | Third DNS nameserver (optional) | Empty | No |
| `DNS_NS4` | Fourth DNS nameserver (optional) | Empty | No |
| `DNS_HOSTMASTER` | DNS hostmaster email | `hostmaster.example.com` | Yes |

### DNS Zone Settings

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_DNS_TTL` | Default TTL for new records (seconds) | `86400` | No |
| `PA_DNS_SOA_REFRESH` | SOA refresh interval (seconds) | `28800` | No |
| `PA_DNS_SOA_RETRY` | SOA retry interval (seconds) | `7200` | No |
| `PA_DNS_SOA_EXPIRE` | SOA expire time (seconds) | `604800` | No |
| `PA_DNS_SOA_MINIMUM` | SOA minimum TTL (seconds) | `86400` | No |
| `PA_DNS_ZONE_TYPE_DEFAULT` | Default zone type: `MASTER` or `NATIVE` | `MASTER` | No |

### DNS Validation Settings

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_DNS_STRICT_TLD_CHECK` | Enable strict TLD validation | `false` | No |
| `PA_DNS_TOP_LEVEL_TLD_CHECK` | Prevent top-level domain creation | `false` | No |
| `PA_DNS_THIRD_LEVEL_CHECK` | Prevent third-level domain creation | `false` | No |
| `PA_DNS_TXT_AUTO_QUOTE` | Automatically quote TXT records | `false` | No |
| `PA_DNS_PREVENT_DUPLICATE_PTR` | Prevent duplicate PTR records in batch operations | `true` | No |

### DNS Record Types

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_DNS_DOMAIN_RECORD_TYPES` | Comma-separated list of allowed domain zone record types | All defaults | No |
| `PA_DNS_REVERSE_RECORD_TYPES` | Comma-separated list of allowed reverse zone record types | All defaults | No |

**Examples:**
- `PA_DNS_DOMAIN_RECORD_TYPES=A,AAAA,CNAME,MX,TXT`
- `PA_DNS_REVERSE_RECORD_TYPES=PTR,NS,SOA,TXT`

### DNSSEC Configuration

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_DNSSEC_ENABLED` | Enable DNSSEC functionality | `false` | No |
| `PA_DNSSEC_DEBUG` | Enable DNSSEC debug logging | `false` | No |

### Security Configuration

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_SESSION_KEY` | Custom session key (recommended for production) | Auto-generated | No |


### Multi-Factor Authentication (MFA)

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_MFA_ENABLED` | Enable MFA functionality | `false` | No |
| `PA_MFA_APP_ENABLED` | Enable authenticator app option | `true` | No |
| `PA_MFA_EMAIL_ENABLED` | Enable email verification option | `true` | No |
| `PA_MFA_RECOVERY_CODES` | Number of recovery codes to generate | `8` | No |
| `PA_MFA_RECOVERY_CODE_LENGTH` | Length of recovery codes | `10` | No |

### Recaptcha

| `PA_RECAPTCHA_ENABLED` | Enable reCAPTCHA on login form | `false` | No |
| `PA_RECAPTCHA_SITE_KEY` | reCAPTCHA site key (public key) | Empty | No |
| `PA_RECAPTCHA_SECRET_KEY` | reCAPTCHA secret key (private key) | Empty | No |

### Mail Configuration

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_MAIL_ENABLED` | Enable email functionality | `true` | No |
| `PA_MAIL_TRANSPORT` | Mail transport method: `php`, `smtp`, `sendmail` | `php` | No |
| `PA_SMTP_HOST` | SMTP server hostname | Empty | No |
| `PA_SMTP_PORT` | SMTP server port | `587` | No |
| `PA_SMTP_USER` | SMTP authentication username | Empty | No |
| `PA_SMTP_PASSWORD` | SMTP authentication password | Empty | No |
| `PA_SMTP_ENCRYPTION` | SMTP encryption: `tls`, `ssl`, or empty | `tls` | No |
| `PA_MAIL_FROM` | Default "from" email address | Empty | No |
| `PA_MAIL_FROM_NAME` | Default "from" name | Empty | No |

### Interface Settings

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_APP_TITLE` | Application title displayed in browser | `Poweradmin` | No |
| `PA_DEFAULT_LANGUAGE` | Default interface language | `en_EN` | No |
| `PA_ENABLED_LANGUAGES` | Comma-separated list of enabled languages | `cs_CZ,de_DE,en_EN,es_ES,fr_FR,it_IT,ja_JP,lt_LT,nb_NO,nl_NL,pl_PL,pt_PT,ru_RU,tr_TR,zh_CN` | No |
| `PA_SESSION_TIMEOUT` | Session timeout in seconds | `1800` | No |
| `PA_ROWS_PER_PAGE` | Number of rows per page | `10` | No |
| `PA_THEME` | Theme name to use | `default` | No |
| `PA_STYLE` | UI style: `light` or `dark` | `light` | No |
| `PA_THEME_BASE_PATH` | Base path for theme templates | `templates` | No |
| `PA_BASE_URL_PREFIX` | Base URL prefix for subdirectory deployments | Empty | No |

### Interface UI Elements

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_SHOW_RECORD_ID` | Show record ID column in edit mode | `true` | No |
| `PA_POSITION_RECORD_FORM_TOP` | Position add record form at the top | `true` | No |
| `PA_POSITION_SAVE_BUTTON_TOP` | Position save button at the top | `false` | No |
| `PA_SHOW_ZONE_COMMENTS` | Show zone comments | `true` | No |
| `PA_SHOW_RECORD_COMMENTS` | Show record comments | `false` | No |
| `PA_DISPLAY_SERIAL_IN_ZONE_LIST` | Display serial in zone list | `false` | No |
| `PA_DISPLAY_TEMPLATE_IN_ZONE_LIST` | Display template in zone list | `false` | No |
| `PA_DISPLAY_FULLNAME_IN_ZONE_LIST` | Show user's full name in zone lists | `false` | No |
| `PA_SEARCH_GROUP_RECORDS` | Group records in search results | `false` | No |
| `PA_REVERSE_ZONE_SORT` | Reverse zone sorting: `natural` or `hierarchical` | `natural` | No |
| `PA_SHOW_PDNS_STATUS` | Show PowerDNS status page | `false` | No |
| `PA_ADD_REVERSE_RECORD` | Enable PTR record checkbox | `true` | No |
| `PA_ADD_DOMAIN_RECORD` | Enable A/AAAA record checkbox | `true` | No |
| `PA_DISPLAY_HOSTNAME_ONLY` | Display only hostname in zone edit | `false` | No |
| `PA_ENABLE_CONSISTENCY_CHECKS` | Enable consistency checks page | `false` | No |

### API Configuration

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_API_ENABLED` | Enable API functionality | `false` | No |
| `PA_API_BASIC_AUTH_ENABLED` | Enable HTTP Basic Auth for API | `false` | No |
| `PA_API_DOCS_ENABLED` | Enable API documentation at /api/docs | `false` | No |

### PowerDNS API Integration

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_PDNS_API_URL` | PowerDNS API URL (e.g., http://127.0.0.1:8081) | Empty | No |
| `PA_PDNS_API_KEY` | PowerDNS API key | Empty | No |
| `PA_PDNS_SERVER_NAME` | PowerDNS server name for API calls | `localhost` | No |

### LDAP Authentication

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_LDAP_ENABLED` | Enable LDAP authentication | `false` | No |
| `PA_LDAP_URI` | LDAP server URI | Empty | No |
| `PA_LDAP_BASE_DN` | Base DN where users are stored | Empty | No |
| `PA_LDAP_BIND_DN` | Bind DN for LDAP authentication | Empty | No |
| `PA_LDAP_BIND_PASSWORD` | LDAP bind password | Empty | No |

### OIDC (OpenID Connect) Authentication

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_OIDC_ENABLED` | Enable OIDC authentication | `false` | No |
| `PA_OIDC_AUTO_PROVISION` | Automatically create user accounts from OIDC | `true` | No |
| `PA_OIDC_LINK_BY_EMAIL` | Link OIDC accounts to existing users by email | `true` | No |
| `PA_OIDC_SYNC_USER_INFO` | Sync user information from OIDC provider | `true` | No |
| `PA_OIDC_DEFAULT_PERMISSION_TEMPLATE` | Default permission template for new OIDC users | `Administrator` | No |

### OIDC Azure AD Provider

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_OIDC_AZURE_ENABLED` | Enable Azure AD provider | `false` | No |
| `PA_OIDC_AZURE_NAME` | Provider name | `Microsoft Azure AD` | No |
| `PA_OIDC_AZURE_DISPLAY_NAME` | Display name for login button | `Sign in with Microsoft` | No |
| `PA_OIDC_AZURE_CLIENT_ID` | Application (client) ID from Azure | Empty | Yes if Azure enabled |
| `PA_OIDC_AZURE_CLIENT_SECRET` | Client secret from Azure | Empty | Yes if Azure enabled |
| `PA_OIDC_AZURE_TENANT` | Tenant ID or 'common' for multi-tenant | `common` | No |
| `PA_OIDC_AZURE_AUTO_DISCOVERY` | Use auto-discovery | `true` | No |
| `PA_OIDC_AZURE_METADATA_URL` | Metadata URL for discovery | Azure's standard URL | No |

### OIDC Google OAuth Provider

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_OIDC_GOOGLE_ENABLED` | Enable Google OAuth provider | `false` | No |
| `PA_OIDC_GOOGLE_NAME` | Provider name | `Google` | No |
| `PA_OIDC_GOOGLE_DISPLAY_NAME` | Display name for login button | `Sign in with Google` | No |
| `PA_OIDC_GOOGLE_CLIENT_ID` | Application (client) ID from Google | Empty | Yes if Google enabled |
| `PA_OIDC_GOOGLE_CLIENT_SECRET` | Client secret from Google | Empty | Yes if Google enabled |
| `PA_OIDC_GOOGLE_TENANT` | Tenant ID or 'common' for multi-tenant | `common` | No |
| `PA_OIDC_GOOGLE_AUTO_DISCOVERY` | Use auto-discovery | `true` | No |
| `PA_OIDC_GOOGLE_METADATA_URL` | Metadata URL for discovery | Google's standard URL | No |

### Admin User Creation

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_CREATE_ADMIN` | Create initial admin user (true/false/1/yes) | `false` | No |
| `PA_ADMIN_USERNAME` | Admin username | `admin` | No |
| `PA_ADMIN_PASSWORD` | Admin password (auto-generated if not set) | Auto-generated | No |
| `PA_ADMIN_EMAIL` | Admin email address | `admin@example.com` | No |
| `PA_ADMIN_FULLNAME` | Admin full name | `Administrator` | No |

### Miscellaneous Settings

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_TIMEZONE` | Default timezone | `UTC` | No |

### Configuration Override

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `PA_CONFIG_PATH` | Path to custom settings.php file | Empty | No |

## Docker Compose Example

### SQLite (Default)
```yaml
version: '3.8'
services:
  poweradmin:
    image: edmondas/poweradmin
    ports:
      - "80:80"
```

### MySQL Setup with Advanced Configuration
```yaml
version: '3.8'
services:
  poweradmin:
    image: edmondas/poweradmin
    ports:
      - "80:80"
    environment:
      # Database Configuration
      - DB_TYPE=mysql
      - DB_HOST=mysql
      - DB_USER=poweradmin
      - DB_PASS=password
      - DB_NAME=poweradmin

      # DNS Configuration
      - DNS_NS1=ns1.yourdomain.com
      - DNS_NS2=ns2.yourdomain.com
      - DNS_HOSTMASTER=hostmaster.yourdomain.com

      # Interface Configuration
      - PA_APP_TITLE=My DNS Admin
      - PA_DEFAULT_LANGUAGE=en_EN

      # Mail Configuration (SMTP)
      - PA_MAIL_ENABLED=true
      - PA_MAIL_TRANSPORT=smtp
      - PA_SMTP_HOST=smtp.gmail.com
      - PA_SMTP_PORT=587
      - PA_SMTP_USER=your-email@gmail.com
      - PA_SMTP_PASSWORD=your-app-password
      - PA_SMTP_ENCRYPTION=tls
      - PA_MAIL_FROM=noreply@yourdomain.com
      - PA_MAIL_FROM_NAME=DNS Admin

      # API Configuration
      - PA_API_ENABLED=true
      - PA_API_DOCS_ENABLED=true

      # Security Configuration
      - PA_RECAPTCHA_ENABLED=true
      - PA_RECAPTCHA_SITE_KEY=your-recaptcha-site-key
      - PA_RECAPTCHA_SECRET_KEY=your-recaptcha-secret-key

      # PowerDNS API Integration
      - PA_PDNS_API_URL=http://powerdns:8081
      - PA_PDNS_API_KEY=your-pdns-api-key

      # Miscellaneous
      - PA_TIMEZONE=America/New_York
    depends_on:
      - mysql

  mysql:
    image: mysql:8.0
    environment:
      - MYSQL_ROOT_PASSWORD=rootpassword
      - MYSQL_DATABASE=poweradmin
      - MYSQL_USER=poweradmin
      - MYSQL_PASSWORD=password
    volumes:
      - mysql_data:/var/lib/mysql

volumes:
  mysql_data:
```

### PostgreSQL Setup
```yaml
version: '3.8'
services:
  poweradmin:
    image: edmondas/poweradmin
    ports:
      - "80:80"
    environment:
      - DB_TYPE=pgsql
      - DB_HOST=postgres
      - DB_USER=poweradmin
      - DB_PASS=password
      - DB_NAME=poweradmin
      - DNS_NS1=ns1.yourdomain.com
      - DNS_NS2=ns2.yourdomain.com
      - DNS_HOSTMASTER=hostmaster.yourdomain.com
    depends_on:
      - postgres

  postgres:
    image: postgres:15
    environment:
      - POSTGRES_DB=poweradmin
      - POSTGRES_USER=poweradmin
      - POSTGRES_PASSWORD=password
    volumes:
      - postgres_data:/var/lib/postgresql/data

volumes:
  postgres_data:
```

## Technical Details

### Web Server Configuration

The Docker image uses FrankenPHP with a custom Caddyfile that provides:

- **URL Rewriting**: All PHP routes are properly handled through Caddy's rewrite rules
- **API Support**: RESTful API endpoints with proper routing and CORS headers
- **Static Assets**: Efficient serving of CSS, JS, images, and fonts
- **Security**: Built-in protection against access to sensitive files and directories
- **Performance**: Gzip compression and static asset caching

### Supported PHP Extensions

- `gettext` - Internationalization support
- `intl` - Unicode and internationalization functions
- `mysqli` - MySQL database support
- `pdo_mysql` - PDO MySQL driver
- `pdo_pgsql` - PDO PostgreSQL driver

### File Structure

- Application files: `/app/`
- SQLite database: `/db/pdns.db`
- Configuration: `/app/config/settings.php`
- Caddy configuration: `/etc/caddy/Caddyfile`

## Building the Image

To build the Docker image locally:

```bash
docker build --no-cache -t poweradmin .

**Note**: For production use, it's recommended to use the official images from Docker Hub (`edmondas/poweradmin`) or GitHub Container Registry (`ghcr.io/poweradmin/poweradmin`) instead of building locally.
```

### Build Process

The Docker build process:
1. Installs required Alpine packages and PHP extensions
2. Copies application files to `/app/`
3. Sets up SQLite database with PowerDNS schema
4. Generates dynamic configuration file
5. Creates Caddy configuration with URL rewriting rules
6. Sets proper permissions for www-data user

## Configuration System

Poweradmin Docker supports **two configuration modes** with automatic priority handling:

### Configuration Priority (Highest to Lowest)

1. **Custom Configuration File** (`PA_CONFIG_PATH`) - Complete control
2. **Environment Variables** - Individual setting overrides (fallback)

### How It Works

- **Custom Config**: If `PA_CONFIG_PATH` is set and the file exists, it completely replaces the generated settings
- **Environment Variables**: If no custom config is provided, settings are generated from environment variables
- **Automatic Generation**: The container automatically detects which mode to use at startup

### Benefits

- **Simple Deployments**: Use environment variables for quick setup
- **Advanced Configurations**: Mount custom PHP config files for complete control
- **No Conflicts**: Clear priority system prevents configuration conflicts
- **Runtime Flexibility**: Config is resolved at container startup, not build time

## Configuration Examples

### Production Deployment with SMTP and Security

```bash
docker run -d --name poweradmin -p 80:80 \
  -e DB_TYPE=mysql \
  -e DB_HOST=db.example.com \
  -e DB_USER=poweradmin \
  -e DB_PASS=secure_password \
  -e DB_NAME=poweradmin \
  -e DNS_NS1=ns1.example.com \
  -e DNS_NS2=ns2.example.com \
  -e DNS_HOSTMASTER=hostmaster@example.com \
  -e PA_APP_TITLE="Production DNS Admin" \
  -e PA_MAIL_ENABLED=true \
  -e PA_MAIL_TRANSPORT=smtp \
  -e PA_SMTP_HOST=smtp.example.com \
  -e PA_SMTP_PORT=587 \
  -e PA_SMTP_USER=noreply@example.com \
  -e PA_SMTP_PASSWORD=smtp_password \
  -e PA_SMTP_ENCRYPTION=tls \
  -e PA_MAIL_FROM=noreply@example.com \
  -e PA_RECAPTCHA_ENABLED=true \
  -e PA_RECAPTCHA_SITE_KEY=your_site_key \
  -e PA_RECAPTCHA_SECRET_KEY=your_secret_key \
  -e PA_API_ENABLED=true \
  -e PA_TIMEZONE=America/New_York \
  edmondas/poweradmin
```

### Development Environment with API

```bash
docker run -d --name poweradmin-dev -p 8080:80 \
  -e PA_APP_TITLE="Development DNS Admin" \
  -e PA_API_ENABLED=true \
  -e PA_API_DOCS_ENABLED=true \
  -e PA_MAIL_ENABLED=false \
  edmondas/poweradmin
```

### LDAP Integration Example

```bash
docker run -d --name poweradmin -p 80:80 \
  -e DB_TYPE=pgsql \
  -e DB_HOST=postgres.example.com \
  -e DB_USER=poweradmin \
  -e DB_PASS=secure_password \
  -e DB_NAME=poweradmin \
  -e PA_LDAP_ENABLED=true \
  -e PA_LDAP_URI=ldaps://ldap.example.com:636 \
  -e PA_LDAP_BASE_DN="ou=users,dc=example,dc=com" \
  -e PA_LDAP_BIND_DN="cn=admin,dc=example,dc=com" \
  -e PA_LDAP_BIND_PASSWORD=ldap_password \
  edmondas/poweradmin
```

### OIDC Azure AD Integration Example

```bash
docker run -d --name poweradmin -p 80:80 \
  -e DB_TYPE=mysql \
  -e DB_HOST=mysql.example.com \
  -e DB_USER=poweradmin \
  -e DB_PASS=secure_password \
  -e DB_NAME=poweradmin \
  -e PA_OIDC_ENABLED=true \
  -e PA_OIDC_AZURE_ENABLED=true \
  -e PA_OIDC_AZURE_CLIENT_ID=your-azure-client-id \
  -e PA_OIDC_AZURE_CLIENT_SECRET=your-azure-client-secret \
  -e PA_OIDC_AZURE_TENANT=your-tenant-id \
  edmondas/poweradmin
```

### OIDC Google OAuth Integration Example

```bash
docker run -d --name poweradmin -p 80:80 \
  -e DB_TYPE=mysql \
  -e DB_HOST=mysql.example.com \
  -e DB_USER=poweradmin \
  -e DB_PASS=secure_password \
  -e DB_NAME=poweradmin \
  -e PA_OIDC_ENABLED=true \
  -e PA_OIDC_GOOGLE_ENABLED=true \
  -e PA_OIDC_GOOGLE_CLIENT_ID=your-google-client-id \
  -e PA_OIDC_GOOGLE_CLIENT_SECRET=your-google-client-secret \
  -e PA_OIDC_GOOGLE_TENANT=your-tenant-id \
  edmondas/poweradmin
```

### Multi-Factor Authentication Example

```bash
docker run -d --name poweradmin -p 80:80 \
  -e DB_TYPE=sqlite \
  -e PA_MFA_ENABLED=true \
  -e PA_MFA_APP_ENABLED=true \
  -e PA_MFA_EMAIL_ENABLED=true \
  -e PA_MFA_RECOVERY_CODES=10 \
  -e PA_MFA_RECOVERY_CODE_LENGTH=12 \
  -e PA_MAIL_ENABLED=true \
  -e PA_MAIL_TRANSPORT=smtp \
  -e PA_SMTP_HOST=smtp.gmail.com \
  -e PA_SMTP_PORT=587 \
  -e PA_SMTP_USER=your-email@gmail.com \
  -e PA_SMTP_PASSWORD=your-app-password \
  -e PA_SMTP_ENCRYPTION=tls \
  -e PA_MAIL_FROM=noreply@yourdomain.com \
  edmondas/poweradmin
```

### Advanced DNS Configuration Example

```bash
docker run -d --name poweradmin -p 80:80 \
  -e DB_TYPE=mysql \
  -e DB_HOST=mysql.example.com \
  -e DB_USER=poweradmin \
  -e DB_PASS=secure_password \
  -e DB_NAME=poweradmin \
  -e DNS_NS1=ns1.yourdomain.com \
  -e DNS_NS2=ns2.yourdomain.com \
  -e DNS_HOSTMASTER=hostmaster@yourdomain.com \
  -e PA_DNS_TTL=3600 \
  -e PA_DNS_SOA_REFRESH=14400 \
  -e PA_DNS_SOA_RETRY=3600 \
  -e PA_DNS_SOA_EXPIRE=1209600 \
  -e PA_DNS_SOA_MINIMUM=3600 \
  -e PA_DNS_ZONE_TYPE_DEFAULT=NATIVE \
  -e PA_DNS_STRICT_TLD_CHECK=true \
  -e PA_DNS_TOP_LEVEL_TLD_CHECK=true \
  -e PA_DNS_DOMAIN_RECORD_TYPES=A,AAAA,CNAME,MX,TXT,SRV,CAA \
  -e PA_DNS_REVERSE_RECORD_TYPES=PTR,NS,SOA,TXT \
  -e PA_DNSSEC_ENABLED=true \
  -e PA_DNSSEC_DEBUG=false \
  edmondas/poweradmin
```

### Custom Configuration File

Mount your own `settings.php` file for complete configuration control. Custom config files override all environment variables.

```bash
# Create custom configuration
cat > ./custom-settings.php << 'EOF'
<?php
return [
    'database' => [
        'type' => 'mysql',
        'host' => 'db.example.com',
        'user' => 'poweradmin',
        'password' => 'secure_password',
        'name' => 'poweradmin',
    ],
    'interface' => [
        'title' => 'My Custom DNS Admin',
        'language' => 'de_DE',
        'theme' => 'dark',
    ],
    'security' => [
        'session_key' => 'your-secure-64-character-session-key-here',
    ],
    'dns' => [
        'hostmaster' => 'hostmaster@yourdomain.com',
        'ns1' => 'ns1.yourdomain.com',
        'ns2' => 'ns2.yourdomain.com',
    ],
    // ... any other custom settings
];
EOF

# Run with custom config
docker run -d --name poweradmin -p 80:80 \
  -v $(pwd)/custom-settings.php:/config/custom.php:ro \
  -e PA_CONFIG_PATH=/config/custom.php \
  edmondas/poweradmin
```

### Docker Compose with Custom Config

```yaml
version: '3.8'
services:
  poweradmin:
    image: edmondas/poweradmin
    ports:
      - "80:80"
    environment:
      - PA_CONFIG_PATH=/config/custom.php
    volumes:
      - ./custom-settings.php:/config/custom.php:ro
    depends_on:
      - mysql

  mysql:
    image: mysql:8.0
    environment:
      - MYSQL_ROOT_PASSWORD=rootpassword
      - MYSQL_DATABASE=poweradmin
      - MYSQL_USER=poweradmin
      - MYSQL_PASSWORD=password
    volumes:
      - mysql_data:/var/lib/mysql

volumes:
  mysql_data:
```

## Performance Benefits

FrankenPHP provides significant performance improvements over traditional PHP deployments:

- **Worker Mode**: PHP processes persist between requests, eliminating bootstrap overhead
- **Memory Efficiency**: Reduced memory usage through process reuse
- **HTTP/2 & HTTP/3**: Modern protocol support for faster loading
- **Built-in Compression**: Automatic gzip compression for better bandwidth usage
- **Static Asset Optimization**: Efficient serving with proper caching headers

## Troubleshooting

### Common Issues

1. **Permission Errors**: Ensure the container has proper permissions for `/db/` and `/app/` directories
2. **Database Connection**: For external databases, ensure network connectivity and credentials
3. **Port Conflicts**: If port 80 is in use, map to a different port: `-p 8080:80`

### Logs

View container logs for debugging:
```bash
docker logs poweradmin
```

For more detailed debug information, enable debug logging:
```bash
docker run -e DEBUG=true [other options] edmondas/poweradmin
```

This will show detailed information about:
- Database validation steps and file checks
- DNS configuration validation with actual values
- Individual validation function progress
- Configuration loading process

**Note**: By default, only a single "Configuration validation completed successfully" message is shown when all validations pass. Debug mode provides step-by-step details for troubleshooting.

### Container Shell Access

Access the container for debugging:
```bash
docker exec -it poweradmin /bin/sh
```

## Admin User Creation

The container can automatically create an initial admin user during startup. This is useful for first-time deployments and eliminates the need for manual database manipulation.

### Environment Variables

- **PA_CREATE_ADMIN**: Set to `1`, `true`, or `yes` to enable admin user creation
- **PA_ADMIN_USERNAME**: Admin username (default: `admin`)
- **PA_ADMIN_PASSWORD**: Admin password (default: `admin`)
- **PA_ADMIN_EMAIL**: Admin email (default: `admin@example.com`)
- **PA_ADMIN_FULLNAME**: Admin full name (default: `Administrator`)

### Examples

Basic admin user creation:
```bash
docker run -d --name poweradmin -p 80:80 \
  -e PA_CREATE_ADMIN=1 \
  edmondas/poweradmin
```

Custom admin user:
```bash
docker run -d --name poweradmin -p 80:80 \
  -v poweradmin-db:/db \
  -e PA_CREATE_ADMIN=1 \
  -e PA_ADMIN_USERNAME=admin \
  -e PA_ADMIN_PASSWORD=secure_password \
  -e PA_ADMIN_EMAIL=admin@yourdomain.com \
  -e PA_ADMIN_FULLNAME="System Administrator" \
  edmondas/poweradmin
```

With Docker secrets:
```bash
docker run -d --name poweradmin -p 80:80 \
  -v poweradmin-db:/db \
  -e PA_CREATE_ADMIN=1 \
  -e PA_ADMIN_USERNAME=admin \
  -e PA_ADMIN_PASSWORD__FILE=/run/secrets/admin_password \
  -e PA_ADMIN_EMAIL=admin@yourdomain.com \
  -v /path/to/admin_password:/run/secrets/admin_password:ro \
  edmondas/poweradmin
```

### Behavior

- The admin user will only be created if it doesn't already exist in the database
- On subsequent container restarts with the same database, the creation will be skipped
- Works with all supported database types (SQLite, MySQL, PostgreSQL)
- Admin users are created with full administrative permissions (permission template 1)
- If admin user creation fails, the container will exit with an error

### Supported Database Types

The admin user creation works with:
- **SQLite**: Default configuration, no additional setup required
- **MySQL**: Requires proper database connection configuration
- **PostgreSQL**: Requires proper database connection configuration

## Access

Once running, access Poweradmin at `http://localhost`.

### Default Credentials

If you enabled admin user creation with `PA_CREATE_ADMIN=1`:
- Username: Value of `PA_ADMIN_USERNAME` (default: `admin`)
- Password: A secure password is auto-generated and displayed in the container logs (unless you set `PA_ADMIN_PASSWORD` explicitly)

**Important**: Check the container logs for the generated password when using `PA_CREATE_ADMIN=1`.

### Manual Setup

If you didn't use the automated admin user creation, you'll need to create an admin user manually by accessing the database directly or through the web interface installation process.

The application will automatically redirect to the login page and all static assets (CSS, JavaScript, images) will load correctly through FrankenPHP's optimized serving.
