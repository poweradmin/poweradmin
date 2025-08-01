# PowerAdmin SQL Schema Files

This directory contains SQL schema files for initializing PowerAdmin with different database backends.

## Installation Order

**Important:** PowerDNS database schema must be installed BEFORE setting up PowerAdmin. PowerAdmin extends the PowerDNS database with additional tables for its management interface.

1. Install PowerDNS schema first (see PowerDNS Schema Files section below)
2. Install PowerAdmin schema on top of the existing PowerDNS database
3. Create an admin user for PowerAdmin

## PowerDNS Schema Files

The `pdns/` directory contains PowerDNS schema files for different versions:

- `pdns/45/` - PowerDNS 4.5.x schemas
- `pdns/46/` - PowerDNS 4.6.x schemas
- `pdns/47/` - PowerDNS 4.7.x schemas
- `pdns/48/` - PowerDNS 4.8.x schemas
- `pdns/49/` - PowerDNS 4.9.x schemas

Each version directory contains:
- `schema.mysql.sql` - MySQL/MariaDB schema
- `schema.pgsql.sql` - PostgreSQL schema
- `schema.sqlite3.sql` - SQLite schema

Choose the schema files that match your PowerDNS version.

## PowerAdmin Database Schema Files

- `poweradmin-mysql-db-structure.sql` - MySQL/MariaDB schema
- `poweradmin-pgsql-db-structure.sql` - PostgreSQL schema  
- `poweradmin-sqlite-db-structure.sql` - SQLite schema

## Creating an Admin User

The database schemas no longer include a default admin user for security reasons. You need to create an admin user manually using SQL commands.

### Manual SQL Commands

To create an admin user, use these SQL commands:

#### For MySQL/MariaDB:
```sql
-- Generate password hash using PHP CLI first:
-- php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"

INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) 
VALUES (
    'admin',
    '$2y$10$...', -- Replace with generated hash
    'Administrator',
    'admin@example.com',
    'Administrator with full rights.',
    1,  -- Administrator permission template
    1,  -- Active
    0   -- Not using LDAP
);
```

#### For PostgreSQL:
```sql
-- Generate password hash using PHP CLI first:
-- php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"

INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) 
VALUES (
    'admin',
    '$2y$10$...', -- Replace with generated hash
    'Administrator',
    'admin@example.com',
    'Administrator with full rights.',
    1,  -- Administrator permission template
    1,  -- Active
    0   -- Not using LDAP
);
```

#### For SQLite:
```sql
-- Generate password hash using PHP CLI first:
-- php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"

INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) 
VALUES (
    'admin',
    '$2y$10$...', -- Replace with generated hash
    'Administrator',
    'admin@example.com',
    'Administrator with full rights.',
    1,  -- Administrator permission template
    1,  -- Active
    0   -- Not using LDAP
);
```

### Using PHP Script

Create a PHP script to generate the password hash:

```php
<?php
// generate-admin-hash.php
$password = 'your-secure-password'; // Change this!
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password hash: $hash\n";
echo "Use this hash in the SQL INSERT statement above.\n";
?>
```

## Security Notes

1. **Never use default passwords** in production environments
2. **Always generate strong passwords** for admin accounts
3. **Change passwords immediately** after first login
4. **Use environment variables or secrets** to manage passwords in automated deployments
5. **Enable two-factor authentication** if available

## Permission Templates

The `perm_templ` field references permission templates:
- `1` - Administrator (full access)
- Other templates can be configured through the PowerAdmin interface

## Database Updates

Update scripts are provided for migrating between PowerAdmin versions:
- `poweradmin-*-update-to-*.sql` - Version-specific update scripts