# Poweradmin

[![release](https://img.shields.io/github/v/release/poweradmin/poweradmin)](https://github.com/poweradmin/poweradmin/releases)
[![validations](https://github.com/poweradmin/poweradmin/actions/workflows/php.yml/badge.svg)](https://github.com/poweradmin/poweradmin/actions/workflows/php.yml)
[![license](https://img.shields.io/badge/license-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![php version](https://img.shields.io/badge/php-8.1%2B-blue)](https://www.php.net/)
[![docker pulls](https://img.shields.io/docker/pulls/poweradmin/poweradmin)](https://hub.docker.com/r/poweradmin/poweradmin)
[![docker image size](https://img.shields.io/docker/image-size/poweradmin/poweradmin)](https://hub.docker.com/r/poweradmin/poweradmin)

[Poweradmin](https://www.poweradmin.org) is a friendly web-based DNS administration tool for PowerDNS server. The
interface supports most of
the features of PowerDNS. It is a hybrid solution that uses SQL for most operations and has PowerDNS API support for
DNSSEC operations.

## Features

- Supports all zone types (master, native, and slave)
- Supermasters for automatic provisioning of slave zones
- Zone templates for quick zone creation
- Bulk operations for records and reverse DNS
- IPv6 support
- Multi-language support (15+ languages)
- DNSSEC operations via PowerDNS API
- Light and dark themes
- Search functionality across zones and records
- User and permission management with role-based access
- Ability to add reverse records
- Authentication options:
  - Local database authentication
  - LDAP authentication with custom filter
  - SAML and OIDC authentication
  - Multi-factor authentication (MFA/2FA) with TOTP
- RESTful API with OpenAPI documentation (used by Terraform/OpenTofu provider)
- Docker deployment with FrankenPHP

## Screenshots

### Login Screen

![Login interface with multi-language and MFA support](https://docs.poweradmin.org/screenshots/light_login.png)

### Dashboard

![Dashboard with quick actions and navigation](https://docs.poweradmin.org/screenshots/light_index_page.png)

### Zone Management

![Zone list with sorting and filtering](https://docs.poweradmin.org/screenshots/light_zone_list.png)

### Zone Editor

![Zone editor with inline record management](https://docs.poweradmin.org/screenshots/light_zone_edit.png)

## Installation

For detailed installation instructions, please visit [the official documentation](https://docs.poweradmin.org/installation/).

### Traditional Installation

* **Recommended method - via releases**:
    * Get the latest stable release from [releases](https://github.com/poweradmin/poweradmin/releases)
* **For specific needs - via Git**:
    * **Warning**: The master branch (4.1.x) is used for development and may be unstable. For production use, stick with the stable 4.0.x release.

### Docker Deployment

**Quick Start with Docker**:
```bash
docker run -d \
  --name poweradmin \
  -p 8080:80 \
  -e DB_TYPE=sqlite \
  -e PA_CREATE_ADMIN=1 \
  poweradmin/poweradmin:latest
```

**Important**:
- DB_TYPE environment variable is required (sqlite, mysql, pgsql)
- No admin user is created by default for security reasons. Use `-e PA_CREATE_ADMIN=1` to create an admin user (a secure password will be auto-generated and shown in logs)

* **Docker Hub**: `poweradmin/poweradmin`
* **GitHub Container Registry**: `ghcr.io/poweradmin/poweradmin`
* **Full documentation**: [DOCKER.md](DOCKER.md)
* **Security with Docker Secrets**: [DOCKER-SECRETS.md](DOCKER-SECRETS.md)

Features: Multi-database support (SQLite, MySQL, PostgreSQL), Docker secrets integration, FrankenPHP for enhanced performance.

## Requirements

* PHP 8.1 or higher (including 8.2, 8.3, 8.4, etc.)
* PHP extensions: intl, gettext, openssl, filter, tokenizer, pdo, xml, pdo-mysql/pdo-pgsql/pdo-sqlite, ldap (optional)
* MySQL 5.7.x/8.x, MariaDB, PostgreSQL or SQLite database
* PowerDNS authoritative server 4.0.0+ (including 5.0)

## Tested on

**Versions:**
- **4.1.x (development)**: PHP 8.2.28, PowerDNS 4.7.4, MariaDB 10.11.10, MySQL 9.1.0, PostgreSQL 16.3, SQLite 3.45.3
- **4.0.x (stable)**: PHP 8.1.31, PowerDNS 4.7.4, MariaDB 10.11.10, MySQL 9.1.0, PostgreSQL 16.3, SQLite 3.45.3
- **3.9.x (previous stable)**: PHP 8.1.31, PowerDNS 4.7.4, MariaDB 10.11.10, MySQL 9.1.0, PostgreSQL 16.3, SQLite 3.45.3

## Contributing

We welcome contributions to Poweradmin! As the sole maintainer of this non-profit project, I work alongside our amazing [contributors](https://github.com/poweradmin/poweradmin/graphs/contributors). See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## Support the Project

Poweradmin is independently developed and maintained. Your support helps keep the project alive and growing.

[![JetBrains logo.](https://resources.jetbrains.com/storage/products/company/brand/logos/jetbrains.svg)](https://jb.gg/OpenSourceSupport)

JetBrains provides IDE licenses used for development of this project.

### Organizations Supporting Development

* HLkomm Telekommunikations GmbH
* IRAM (Institut de Radioastronomie Millimétrique)

### Individual Donors

* Stefano Rizzetto
* Asher Manangan
* Michiel Visser
* Gino Cremer
* Arthur Mayer
* Dylan Blanqué
* trendymail

For feature sponsorship, to speed up development of specific features, or to discuss ideas and issues, please [contact me](https://github.com/edmondas).

## Related Projects

* [terraform-provider-poweradmin](https://github.com/poweradmin/terraform-provider-poweradmin) - Terraform/OpenTofu provider for managing DNS zones and records through Poweradmin
* [certbot-dns-poweradmin](https://github.com/poweradmin/certbot-dns-poweradmin) - Certbot DNS plugin for Poweradmin to automate Let's Encrypt certificate issuance with DNS-01 challenge
* [external-dns-poweradmin-plugin](https://github.com/poweradmin/external-dns-poweradmin-plugin) - ExternalDNS webhook plugin for Poweradmin to synchronize Kubernetes DNS records

## Note

Poweradmin is an independent community project, not affiliated with [PowerDNS.com](https://www.powerdns.com/index.html) or [Open-Xchange](https://www.open-xchange.com).

## License

This project is licensed under the GNU General Public License v3.0. See the LICENSE file for more details.
