# Development Environment

This folder contains the Docker-based development environment for Poweradmin with multi-database support.

## Quick Start

```bash
docker-compose up -d
```

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

## Port Mappings

### Poweradmin Web Interfaces (SQL backend)
- **MySQL + SQL** (Nginx): http://localhost:8080
- **PostgreSQL + SQL** (Apache): http://localhost:8081
- **SQLite + SQL** (Caddy): http://localhost:8082

### Poweradmin Web Interfaces (API backend)
- **MySQL + API** (Nginx): http://localhost:8083
- **PostgreSQL + API** (Nginx): http://localhost:8084
- **SQLite + API** (Nginx): http://localhost:8085

### PowerDNS Servers (with DNSSEC)
- **MySQL backend**: DNS port 1053, API port 8181
- **PostgreSQL backend**: DNS port 1054, API port 8182
- **SQLite backend**: DNS port 1055, API port 8183

### Admin Tools
- **Adminer** (DB management): http://localhost:8090
- **phpLDAPadmin**: https://localhost:8443

### Databases
- **MariaDB**: localhost:3306
- **PostgreSQL**: localhost:5432
- **LDAP**: localhost:389, 636 (LDAPS)

## Configuration Files

### Web Server Configs
- `conf/nginx.conf` - Nginx configuration (MySQL + SQL)
- `conf/apache-vhost.conf` - Apache virtual host (PostgreSQL + SQL)
- `conf/Caddyfile` - Caddy configuration (SQLite + SQL)
- `conf/nginx-mysql-api.conf` - Nginx configuration (MySQL + API)
- `conf/nginx-pgsql-api.conf` - Nginx configuration (PostgreSQL + API)
- `conf/nginx-sqlite-api.conf` - Nginx configuration (SQLite + API)

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
- **MariaDB**: user: `pdns`, pass: `poweradmin`, db: `pdns` (app tables in `poweradmin` db)
- **PostgreSQL**: user: `pdns`, pass: `poweradmin`, db: `pdns`
- **SQLite**: `/data/pdns.db` (mounted in containers)

## Test Credentials

After importing test data (`.devcontainer/scripts/import-test-data.sh`):
- Username: `admin`, `manager`, `client`, `viewer`, `noperm`, `inactive`
- Password: `poweradmin123`
