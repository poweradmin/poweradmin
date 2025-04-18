# Poweradmin Configuration

This directory contains the configuration files for Poweradmin.

## Important Version Information

- **Version 4.0.0**: Support for both old (`inc/config.inc.php`) and new (`config/settings.php`) configuration formats
- **Next Major Release**: Support for old configuration format will be completely removed

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

## Migration from Old Configuration

If you're migrating from an earlier version of Poweradmin that used `inc/config.inc.php`, you can use the migration script:

```
php config/migrate-config.php
```

This will:
1. Read your existing configuration from `inc/config.inc.php`
2. Convert it to the new format
3. Save it as `config/settings.php`

### Important Migration Notes

#### Prerequisites
- The script requires a valid `inc/config.inc.php` file to exist (it will not use config-defaults.inc.php)
- The script must be run from the command line for security reasons

#### Migration Process
1. The script reads your existing old configuration from `inc/config.inc.php`
2. It converts only settings that were present in your old configuration file
3. New features that didn't exist in the old configuration use defaults from settings.defaults.php
4. The migrated configuration is saved to `config/settings.php` in a readable format
5. You have the option to back up your old configuration file

#### Key Conversions
- **Theme/Style**: Old style values are converted to the new format:
  - 'ignite' → 'light'
  - 'spark' → 'dark'
  - Any other value will default to 'light'
- **Templates**: The theme is always set to 'default', with the template path preserved
- **SOA Settings**: The combined SOA string (e.g., '28800 7200 604800 86400') is split into individual settings:
  - refresh, retry, expire, minimum
- **New Features**: Password policies, account lockout settings, mail configuration, and record types all use the defaults from settings.defaults.php

It is recommended to migrate to the new configuration format as soon as possible, as support for the old format will be completely removed in the next major release. After migration, both configuration formats will work in version 4.0.0, but future versions will only support the new format.

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
    'style' => 'dark', // Options: 'light', 'dark' (old config used 'ignite'/'spark')
    'rows_per_page' => 15,
],
```