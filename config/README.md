# Poweradmin Configuration

This directory contains the configuration files for Poweradmin.

## Configuration Format

Poweradmin uses a modern PHP-based configuration system with `config/settings.php` as the main configuration file.

## Configuration Files

- `settings.defaults.php`: Sample configuration file with all available settings and their default values
- `settings.php`: Your actual configuration (copy from sample and modify)

## How to Configure Poweradmin

1. Copy the sample configuration file:
   ```
   cp settings.defaults.php settings.php
   ```

2. Edit `settings.php` to match your environment:
   ```
   nano settings.php
   ```

3. At minimum, you should configure:
   - Database connection details
   - Security settings (especially the session key)
   - DNS nameserver information


## Configuration Structure

The configuration is organized into logical groups:

- `database`: Database connection settings (host, port, user, password, name, type, charset, collation)
- `security`: Security-related settings (session_key, password_encryption, password_policy, account_lockout)
- `interface`: User interface settings (language, theme, style, rows_per_page, UI elements)
- `dns`: DNS-related settings (nameservers, TTL, SOA defaults, validation settings)
- `mail`: Email configuration (SMTP, sendmail options, templates)
- `dnssec`: DNSSEC settings (note: the 'command' parameter will be deprecated in the future)
- `pdns_api`: PowerDNS API configuration (url, key)
- `logging`: Logging settings (type, level, database_enabled, syslog options)
- `ldap`: LDAP authentication settings (uri, base_dn, attributes, filters)
- `misc`: Miscellaneous settings (timezone, stats display, experimental features)

## Example

```php
// Database configuration
'database' => [
    'host' => 'localhost',
    'port' => '3306',
    'user' => 'poweradmin',
    'password' => 'your_secure_password',
    'name' => 'powerdns',
    'type' => 'mysql',
    'charset' => 'utf8',
    'collation' => 'utf8_general_ci',
],

// Interface settings
'interface' => [
    'language' => 'en_EN',
    'theme' => 'default',
    'style' => 'dark', // Options: 'light', 'dark'
    'rows_per_page' => 15,
],
```