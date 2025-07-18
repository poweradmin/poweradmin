# Docker Secrets Support for Poweradmin

This document explains how to use Docker secrets with the refactored Poweradmin Docker container.

## Overview

The Poweradmin Docker container now supports Docker secrets for secure handling of sensitive configuration values. Any environment variable can be provided via a file by appending `__FILE` to the variable name.

## How Docker Secrets Work

Instead of passing sensitive values directly as environment variables, you can store them in files and reference those files. The entrypoint script will automatically read the file contents and use them as the actual variable values.

## Usage Examples

### Basic Docker Run with Secrets

```bash
# Create secret files
echo "mySecretPassword" > /tmp/db_password
echo "secret_key_12345" > /tmp/api_key

# Run container with file-based secrets
docker run -d --name poweradmin \
  -p 80:80 \
  -e DB_TYPE=mysql \
  -e DB_HOST=mysql.example.com \
  -e DB_USER=poweradmin \
  -e DB_PASS__FILE=/run/secrets/db_password \
  -e DB_NAME=poweradmin \
  -e PA_PDNS_API_KEY__FILE=/run/secrets/api_key \
  -v /tmp/db_password:/run/secrets/db_password:ro \
  -v /tmp/api_key:/run/secrets/api_key:ro \
  poweradmin
```

### Docker Compose with Secrets

```yaml
version: '3.8'

services:
  poweradmin:
    image: poweradmin
    ports:
      - "80:80"
    environment:
      - DB_TYPE=mysql
      - DB_HOST=mysql
      - DB_USER=poweradmin
      - DB_PASS__FILE=/run/secrets/db_password
      - DB_NAME=poweradmin
      - PA_PDNS_API_KEY__FILE=/run/secrets/pdns_api_key
      - PA_SMTP_PASSWORD__FILE=/run/secrets/smtp_password
    secrets:
      - db_password
      - pdns_api_key
      - smtp_password
    depends_on:
      - mysql

  mysql:
    image: mysql:8.0
    environment:
      - MYSQL_ROOT_PASSWORD=rootpassword
      - MYSQL_DATABASE=poweradmin
      - MYSQL_USER=poweradmin
      - MYSQL_PASSWORD_FILE=/run/secrets/db_password
    secrets:
      - db_password

secrets:
  db_password:
    file: ./secrets/db_password.txt
  pdns_api_key:
    file: ./secrets/pdns_api_key.txt
  smtp_password:
    file: ./secrets/smtp_password.txt
```

### Docker Swarm with External Secrets

```yaml
version: '3.8'

services:
  poweradmin:
    image: poweradmin
    ports:
      - "80:80"
    environment:
      - DB_TYPE=mysql
      - DB_HOST=mysql
      - DB_USER=poweradmin
      - DB_PASS__FILE=/run/secrets/db_password
      - DB_NAME=poweradmin
      - PA_PDNS_API_KEY__FILE=/run/secrets/pdns_api_key
    secrets:
      - db_password
      - pdns_api_key
    deploy:
      replicas: 2

secrets:
  db_password:
    external: true
  pdns_api_key:
    external: true
```

Create the external secrets:
```bash
echo "mySecretPassword" | docker secret create db_password -
echo "pdns_api_key_12345" | docker secret create pdns_api_key -
```

## Supported Secret Variables

Any environment variable can be provided via a secret file by appending `__FILE`:

### Database Configuration
- `DB_PASS__FILE` - Database password
- `DB_USER__FILE` - Database username (if needed)

### API Configuration
- `PA_PDNS_API_KEY__FILE` - PowerDNS API key
- `PA_RECAPTCHA_SECRET_KEY__FILE` - reCAPTCHA secret key
- `PA_RECAPTCHA_SITE_KEY__FILE` - reCAPTCHA site key

### Mail Configuration
- `PA_SMTP_PASSWORD__FILE` - SMTP password
- `PA_SMTP_USER__FILE` - SMTP username

### LDAP Configuration
- `PA_LDAP_BIND_PASSWORD__FILE` - LDAP bind password
- `PA_LDAP_BIND_DN__FILE` - LDAP bind DN

### Security
- `PA_SESSION_KEY__FILE` - Custom session key

## Error Handling

The entrypoint script includes comprehensive error handling:

1. **Mutual Exclusivity**: You cannot set both `VAR_NAME` and `VAR_NAME__FILE` for the same variable
2. **File Validation**: Secret files must exist and be readable
3. **Configuration Validation**: Required variables are validated based on the configuration
4. **Detailed Logging**: All operations are logged with timestamps

## Security Best Practices

1. **File Permissions**: Ensure secret files are only readable by the container user
2. **Read-Only Mounts**: Mount secret files as read-only
3. **Temporary Files**: Clean up secret files after use
4. **Minimal Exposure**: Only expose secrets that are actually needed

## Configuration Priority

The configuration is loaded in this order (highest to lowest priority):

1. `PA_CONFIG_PATH` - Custom configuration file
2. Environment variables (including Docker secrets)
3. Default values

## Example Secret Files Structure

```
secrets/
├── db_password.txt
├── pdns_api_key.txt
├── smtp_password.txt
├── recaptcha_secret.txt
└── ldap_bind_password.txt
```

## Troubleshooting

### Check Secret Loading
The entrypoint script logs all secret processing operations. Look for messages like:
```
[2025-01-18 10:30:00] Getting secret DB_PASS from /run/secrets/db_password
```

### Common Issues
1. **File Not Found**: Ensure the secret file path is correct and the file exists
2. **Permission Denied**: Check that the file is readable by the www-data user
3. **Both Variables Set**: Don't set both `VAR_NAME` and `VAR_NAME__FILE`

### Debug Mode
You can enable debug logging by setting environment variable:
```bash
-e DEBUG=true
```

This will provide more detailed information about the configuration loading process.
