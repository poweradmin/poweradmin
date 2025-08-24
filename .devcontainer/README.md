# Development Environment

This folder contains the Docker-based development environment for Poweradmin.

## Quick Start

```bash
docker-compose up -d
```

## Port Mappings

### Web Servers
- **Nginx**: http://localhost:3000
- **Apache**: http://localhost:3001  
- **Caddy**: http://localhost:3002

### Services
- **Adminer** (DB management): http://localhost:8090
- **LDAP Admin**: https://localhost:8443
- **PowerDNS API**: http://localhost:8081

### Databases
- **MySQL**: localhost:3306
- **PostgreSQL**: localhost:5432
- **LDAP**: localhost:389, 636
- **PowerDNS**: localhost:1053

## Configuration Files

- `conf/nginx.conf` - Nginx configuration
- `conf/Caddyfile` - Caddy configuration
- `conf/pdns.conf` - PowerDNS configuration
- `apache.Dockerfile` - Apache container build
- `docker-compose.yml` - Main orchestration file

## Database Access

Use Adminer at http://localhost:8090 or connect directly:
- **MySQL**: user: `pdns`, pass: `poweradmin`, db: `pdns`
- **PostgreSQL**: user: `pdns`, pass: `poweradmin`, db: `pdns`