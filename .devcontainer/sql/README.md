# Test Database SQL Files

This directory contains SQL scripts for populating test data in Poweradmin development databases.

## Overview

The SQL files in this directory create a comprehensive test environment with:

- **5 Permission Templates**: Administrator, Zone Manager, Client Editor, Read Only, No Access
- **6 Test Users**: Different permission levels and states (active/inactive)
- **4 Test Zones**: Including single-owner and multi-owner zones
- **Sample DNS Records**: SOA, NS, and A records for each zone
- **Comprehensive DNS Records**: ~26 diverse record types per zone for UI testing (A, AAAA, MX, TXT, CNAME, SRV, CAA, disabled records)

## Files

### `test-users-permissions-mysql.sql`
MySQL/MariaDB version of test data for single database setup. Use with MySQL 5.7+ or MariaDB 10.3+.

**Note**: For devcontainer use, see the `-combined.sql` version below.

### `test-users-permissions-pgsql.sql`
PostgreSQL version of test data. Use with PostgreSQL 12+.

### `test-users-permissions-sqlite.sql`
SQLite version of test data. Use with SQLite 3.8+.

**Important**: This script automatically attaches the PowerDNS database at `/data/db/powerdns.db` as `pdns` since the `domains` and `records` tables are in a separate database file. All PowerDNS table references use the `pdns.` prefix.

### `test-users-permissions-mysql-combined.sql`
MySQL/MariaDB version for **devcontainer multi-database setup**. This script handles both the `poweradmin` database (users, permissions, zones) and the `pdns` database (domains, records) using `USE` statements and cross-database joins.

**Recommended for devcontainer**: This is the version used by the import script since the devcontainer keeps PowerDNS tables in a separate `pdns` database.

### `test-dns-records-mysql.sql`
MySQL/MariaDB comprehensive DNS records for UI testing. Adds ~26 diverse record types to `manager-zone.example.com` and `client-zone.example.com` zones.

### `test-dns-records-pgsql.sql`
PostgreSQL comprehensive DNS records for UI testing. Adds ~26 diverse record types to test zones.

### `test-dns-records-sqlite.sql`
SQLite comprehensive DNS records for UI testing. Adds ~26 diverse record types to test zones. Automatically attaches the PowerDNS database.

## Quick Import

### Using the Import Script (Recommended)

```bash
# Import to all databases
./.devcontainer/scripts/import-test-data.sh

# Import to specific database only
./.devcontainer/scripts/import-test-data.sh --mysql
./.devcontainer/scripts/import-test-data.sh --pgsql
./.devcontainer/scripts/import-test-data.sh --sqlite
```

### Manual Import

#### MySQL/MariaDB
```bash
# Use the combined script that handles both poweradmin and pdns databases
docker exec -i mariadb mysql -u root -ppoweradmin < .devcontainer/sql/test-users-permissions-mysql-combined.sql
```

#### PostgreSQL
```bash
# Pass PGPASSWORD into the container environment
docker exec -i -e PGPASSWORD=poweradmin postgres psql -U pdns -d pdns < .devcontainer/sql/test-users-permissions-pgsql.sql
```

#### SQLite
```bash
# Note: The SQLite script automatically attaches /data/db/powerdns.db
docker exec -i sqlite sqlite3 /data/poweradmin.db < .devcontainer/sql/test-users-permissions-sqlite.sql
```

### Using Adminer Web UI
1. Open http://localhost:8090
2. Login with database credentials
3. Select the database
4. Use "SQL command" to paste and execute the appropriate SQL file

## Test Data Structure

### Permission Templates

| ID | Template Name   | Description | Permissions Count |
|----|----------------|-------------|-------------------|
| 1  | Administrator  | Full system access (überuser) | All |
| 2  | Zone Manager   | Full management of own zones | 11 |
| 3  | Client Editor  | Limited editing (no SOA/NS) | 4 |
| 4  | Read Only      | View-only access | 2 |
| 5  | No Access      | No permissions | 0 |

### Test Users

All users have the password: **`poweradmin123`**

| Username | Template | Active | Email | Description |
|----------|----------|--------|-------|-------------|
| admin    | Administrator | Yes | admin@example.com | Full system access |
| manager  | Zone Manager  | Yes | manager@example.com | Can manage own zones |
| client   | Client Editor | Yes | client@example.com | Limited editing rights |
| viewer   | Read Only     | Yes | viewer@example.com | Read-only access |
| noperm   | No Access     | Yes | noperm@example.com | No permissions (for testing) |
| inactive | No Access     | No  | inactive@example.com | Inactive account (cannot login) |

### Test Zones

| Zone Name | Owner(s) | Type | Purpose |
|-----------|----------|------|---------|
| admin-zone.example.com   | admin | MASTER | Admin-owned zone |
| manager-zone.example.com | manager | MASTER | Zone manager's zone |
| client-zone.example.com  | client | MASTER | Client editor's zone |
| shared-zone.example.com  | manager, client | MASTER | **Multi-owner zone** |

Each zone includes:
- SOA record (automatically generated with current timestamp)
- 2 NS records (ns1.example.com, ns2.example.com)
- 1 A record (www subdomain → 192.0.2.1)

#### Comprehensive DNS Records (manager-zone and client-zone)

Additionally, `manager-zone.example.com` and `client-zone.example.com` include comprehensive DNS records for UI testing:

| Record Type | Count | Examples | Purpose |
|-------------|-------|----------|---------|
| A | 7 | www, mail, ftp, blog, shop, api, root | IPv4 addresses for various services |
| AAAA | 3 | www, mail, root | IPv6 support testing |
| MX | 2 | mail (priority 10), mail2 (priority 20) | Mail server configuration with priorities |
| TXT | 3 | SPF, DMARC, DKIM | Long content for UI column width testing |
| CNAME | 3 | cdn, docs, webmail | Alias records |
| SRV | 2 | XMPP, SIP | Service records with priorities |
| CAA | 2 | Let's Encrypt, incident reporting | Certificate authority authorization |
| Disabled | 1 | test-disabled.{zone} | Testing disabled record state |

**Total**: ~26 records per zone (including SOA and NS)

**UI Testing Features**:
- Long TXT records (SPF, DMARC, DKIM) test column width handling
- Multiple records of same type test bulk operations
- Disabled record tests status display
- Various priorities (MX, SRV) test sorting
- Diverse record types test filtering and type-based operations

## Testing Scenarios

### Permission Testing
- **Full Access**: Login as `admin` to test administrator capabilities
- **Zone Management**: Login as `manager` to test zone creation, editing, deletion
- **Limited Editing**: Login as `client` to verify SOA/NS record restrictions
- **Read-Only**: Login as `viewer` to test view-only permissions
- **No Permissions**: Login as `noperm` to verify permission denial handling
- **Inactive User**: Verify `inactive` account cannot login

### Multi-Owner Zone Testing
- Login as `manager` and edit `shared-zone.example.com`
- Login as `client` and verify access to the same zone
- Test permission enforcement (client cannot edit SOA/NS on shared zone)

### Permission Inheritance
- Verify zone ownership transfers
- Test permission template changes
- Validate template-based permission assignments

## Verification Queries

Each SQL file includes verification queries at the end that output:

1. **Permission Templates**: List all templates with permission counts
2. **Users**: List all test users with their assigned templates
3. **Zones and Ownership**: List zones with their owners (including multi-owner zones)

## Database Schema Requirements

These SQL files assume the standard Poweradmin database schema is already installed:

- `users` table
- `perm_templ` and `perm_templ_items` tables
- `domains` and `records` tables (PowerDNS)
- `zones` table (Poweradmin zone ownership)

## Resetting Test Data

To reset/reimport test data:

1. **Delete existing test data** (optional):
   ```sql
   -- Delete test zones
   DELETE FROM zones WHERE domain_id IN (
       SELECT id FROM domains WHERE name LIKE '%.example.com'
   );
   DELETE FROM records WHERE domain_id IN (
       SELECT id FROM domains WHERE name LIKE '%.example.com'
   );
   DELETE FROM domains WHERE name LIKE '%.example.com';

   -- Delete test users (except admin if needed)
   DELETE FROM users WHERE username IN ('manager', 'client', 'viewer', 'noperm', 'inactive');

   -- Delete test permission templates
   DELETE FROM perm_templ_items WHERE templ_id IN (2, 3, 4, 5);
   DELETE FROM perm_templ WHERE id IN (2, 3, 4, 5);
   ```

2. **Reimport**: Run the import script or manual import commands again

## Customization

To modify test data:

1. Edit the appropriate SQL file for your database type
2. Adjust user credentials, permission templates, or zones as needed
3. Update all three SQL files to maintain consistency across database types
4. Reimport using the import script

## Security Notes

- **DO NOT use these credentials in production**
- The password hash is publicly known: `poweradmin123`
- These files are for development and testing only
- Always use strong, unique passwords in production environments

## Troubleshooting

### Import Fails with "Duplicate Entry" Error
The database may already contain test data. Either:
- Reset test data using the deletion queries above
- Modify the SQL to use `INSERT IGNORE` or `ON DUPLICATE KEY UPDATE`

### Container Not Found
Ensure Docker containers are running:
```bash
docker ps
```

Start containers if needed:
```bash
docker-compose up -d  # or docker compose up -d
```

### Permission Denied
Verify database credentials match your environment:
```bash
# MySQL
docker exec mariadb mysql -u root -ppoweradmin -e "SELECT 1"

# PostgreSQL
docker exec postgres psql -U pdns -d poweradmin -c "SELECT 1"
```

### SQLite Database Path
If SQLite import fails, verify the database path:
```bash
docker exec sqlite ls -la /var/lib/poweradmin/
```

Adjust `SQLITE_DB_PATH` environment variable if needed.

## Contributing

When adding new test scenarios:

1. Create corresponding SQL for all three database types
2. Update this README with new test data details
3. Test imports on all database types
4. Document the testing scenario and expected behavior

## Related Documentation

- See `CLAUDE.md` for overall development guidelines
- See `DOCKER.md` for Docker deployment configuration
- See main documentation for Poweradmin architecture details

## Notes

- The comprehensive DNS records (test-dns-records-*.sql) are automatically imported when running the import script
- Records use TEST-NET addresses (192.0.2.0/24, 2001:db8::/32) per RFC 5737 and RFC 3849
- Long TXT records are intentionally included to test UI handling of content that exceeds typical column widths
- All test data is designed for development environments only and should never be used in production
