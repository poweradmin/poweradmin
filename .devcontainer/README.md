# Development Environment

This folder contains the Docker-based development environment for Poweradmin with multi-database support.

## Quick Start

```bash
docker-compose up -d
```

## Architecture

Each web server uses a different database backend with its own PowerDNS instance (DNSSEC enabled):

| Port | Web Server | Database | PowerDNS DNS | PowerDNS API |
|------|------------|----------|--------------|--------------|
| 8080 | Nginx | MySQL/MariaDB | 1053 | 8181 |
| 8081 | Apache | PostgreSQL | 1054 | 8182 |
| 8082 | Caddy | SQLite | 1055 | 8183 |

## Port Mappings

### Poweradmin Web Interfaces
- **MySQL instance** (Nginx): http://localhost:8080
- **PostgreSQL instance** (Apache): http://localhost:8081
- **SQLite instance** (Caddy): http://localhost:8082

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
- `conf/nginx.conf` - Nginx configuration (MySQL backend)
- `conf/apache-vhost.conf` - Apache virtual host
- `conf/Caddyfile` - Caddy configuration (SQLite backend)

### PowerDNS Configs (DNSSEC enabled)
- `conf/pdns-mysql.conf` - PowerDNS for MySQL
- `conf/pdns-pgsql.conf` - PowerDNS for PostgreSQL
- `conf/pdns-sqlite.conf` - PowerDNS for SQLite

### Poweradmin Settings
- `conf/settings-mysql.php` - MySQL database settings
- `conf/settings-pgsql.php` - PostgreSQL database settings
- `conf/settings-sqlite.php` - SQLite database settings

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
- **SQLite**: `/data/poweradmin.db` (mounted in containers)

## Test Credentials

After importing test data (`.devcontainer/scripts/import-test-data.sh`):
- Username: `admin`, `manager`, `client`, `viewer`, `noperm`, `inactive`
- Password: `poweradmin123`
