# Development Environment

This folder contains the Docker-based development environment for Poweradmin with multi-database support.

## Quick Start

The VS Code devcontainer creates the stack as compose project `poweradmin_devcontainer`.
Reuse that name when driving it by hand, otherwise compose forks a second project with
empty volumes and aborts on container-name conflicts:

```bash
docker compose -f .devcontainer/docker-compose.yml --project-directory .devcontainer \
  --project-name poweradmin_devcontainer up -d
```

Then load the fixtures: `.devcontainer/scripts/import-test-data.sh`

Each database family runs its own PHP version (set in `.env`), so one E2E sweep over the three
SQL instances covers three versions at once:

| Family | Instances | PHP |
|--------|-----------|-----|
| MySQL/MariaDB | 8080, 8083, 8086 | `PHP_VERSION_MYSQL` = 8.2 (the supported floor) |
| SQLite | 8082, 8085 | `PHP_VERSION_SQLITE` = 8.3 |
| PostgreSQL | 8081 (Apache), 8084 | `PHP_VERSION_PGSQL` = 8.4 (same as the production image) |
| German-only | 8087 | `PHP_VERSION_DE` = 8.5 (newest release) |

The images are tagged `poweradmin-devcontainer-fpm:<version>`; the first service of a family
builds, the others reuse the tag. After editing `.devcontainer/Dockerfile` or a version in
`.env`, run `docker compose ... build` and recreate the app services.

Xdebug is off by default. Set `PHP_XDEBUG=1` in `.env`, rebuild the fpm images and recreate
the app services; `conf/xdebug.ini` holds the client settings (`host.docker.internal`, port 9003).

Only the web instances (8080-8087) listen on all interfaces. Databases, LDAP, the PowerDNS
DNS/API ports, Adminer and phpLDAPadmin are bound to 127.0.0.1, so they are unreachable from
other machines on your network.

## Architecture

Each database has two Poweradmin instances - one using direct SQL and one using the PowerDNS REST API backend (experimental). All share the same PowerDNS servers (DNSSEC enabled):

### SQL Backend (default - direct database access)

| Port | Web Server | Database | PowerDNS DNS | PowerDNS API |
|------|------------|----------|--------------|--------------|
| 8080 | Nginx | MySQL/MariaDB | 1053 | 8181 |
| 8081 | Apache | PostgreSQL | 1054 | 8182 |
| 8082 | Caddy | SQLite | 1055 | 8183 |

### API Backend (experimental - writes via PowerDNS REST API)

| Port | Web Server | Database | PowerDNS DNS | PowerDNS API |
|------|------------|----------|--------------|--------------|
| 8083 | Nginx | MySQL/MariaDB | 1053 | 8181 |
| 8084 | Nginx | PostgreSQL | 1054 | 8182 |
| 8085 | Nginx | SQLite | 1055 | 8183 |

### Special-purpose instances (MySQL/MariaDB, SQL backend)

| Port | Purpose |
|------|---------|
| 8086 | Subfolder deployment, served under http://localhost:8086/poweradmin/ |
| 8087 | German-only interface (`enabled_languages` restricted to `de_DE`) |

## Port Mappings

### Poweradmin Web Interfaces (SQL backend)
- **MySQL + SQL** (Nginx): http://localhost:8080
- **PostgreSQL + SQL** (Apache): http://localhost:8081
- **SQLite + SQL** (Caddy): http://localhost:8082

### Poweradmin Web Interfaces (API backend)
- **MySQL + API** (Nginx): http://localhost:8083
- **PostgreSQL + API** (Nginx): http://localhost:8084
- **SQLite + API** (Nginx): http://localhost:8085
- **MySQL + Subfolder** (Nginx): http://localhost:8086/poweradmin/
- **MySQL + German only** (Nginx): http://localhost:8087

### PowerDNS Servers (with DNSSEC)
- **MySQL backend**: DNS port 1053, API port 8181
- **PostgreSQL backend**: DNS port 1054, API port 8182
- **SQLite backend**: DNS port 1055, API port 8183
- **LMDB backend** (opt-in, the only backend with views and network mappings): DNS port 1056, API port 8184.
  Start it with `docker compose --profile lmdb up -d pdns-lmdb` (same `-f`/`--project-name` flags as above).

### Admin Tools
- **Adminer** (DB management): http://localhost:8090
- **phpLDAPadmin**: https://localhost:8443

### Databases
- **MariaDB**: localhost:3306
- **PostgreSQL**: localhost:5432
- **LDAP**: localhost:389, 636 (LDAPS)

## Configuration Files

### Web Server Configs
- `conf/nginx.conf.template` - Nginx configuration shared by every nginx front (`FPM_UPSTREAM` selects the PHP-FPM container)
- `conf/nginx-subfolder.conf` - Nginx configuration for the subfolder instance
- `conf/apache-vhost.conf` - Apache virtual host (PostgreSQL + SQL)
- `conf/Caddyfile` - Caddy configuration (SQLite + SQL)

### PowerDNS Configs (DNSSEC enabled)
- `conf/pdns-mysql.conf` - PowerDNS for MySQL
- `conf/pdns-pgsql.conf` - PowerDNS for PostgreSQL
- `conf/pdns-sqlite.conf` - PowerDNS for SQLite

### Poweradmin Settings (SQL backend)
- `conf/settings-mysql-sql.php` - MySQL + SQL backend
- `conf/settings-pgsql-sql.php` - PostgreSQL + SQL backend
- `conf/settings-sqlite-sql.php` - SQLite + SQL backend

### Poweradmin Settings (API backend)
- `conf/settings-mysql-api.php` - MySQL + API backend
- `conf/settings-pgsql-api.php` - PostgreSQL + API backend
- `conf/settings-sqlite-api.php` - SQLite + API backend

### Docker Files
- `Dockerfile` - PHP-FPM container (for Nginx/Caddy)
- `apache.Dockerfile` - Apache with PHP container
- `docker-compose.yml` - Main orchestration file

## Database Access

### Via Adminer
Access Adminer at http://localhost:8090

### Direct Connection
- **MariaDB**: user: `pdns`, pass: `poweradmin`, db: `pdns` (app tables in `poweradmin` db); root password `uberuser`
- **PostgreSQL**: user: `pdns`, pass: `poweradmin`, db: `pdns`
- **SQLite**: `/data/pdns.db` (mounted in containers)

## Test Credentials

After importing test data (`.devcontainer/scripts/import-test-data.sh`):
- Username: `admin`, `manager`, `client`, `viewer`, `noperm`, `inactive`
- Password: `Poweradmin123`

## LDAP Test Users

The `ldap` container bootstraps `ldap/bootstrap/*.ldif` on first start (users, the
`dns-admins` group and a `memberof` overlay for `groupOfNames`). The MySQL import adds the
matching Poweradmin accounts, so LDAP login works on the MySQL instances (8080, 8083, 8086):

- `testuser` / `testpass123` (Administrator template, member of `dns-admins`)
- `testuser2` / `testpass456` (Zone Manager template)

Check the whole chain with `.devcontainer/scripts/verify-ldap-test-setup.sh`.
phpLDAPadmin: http://localhost:8443 (login DN `cn=admin,dc=poweradmin,dc=org`, password `poweradmin`).
