# Poweradmin

[![release](https://img.shields.io/github/v/release/poweradmin/poweradmin)](https://github.com/poweradmin/poweradmin/releases)
[![validations](https://github.com/poweradmin/poweradmin/actions/workflows/php.yml/badge.svg)](https://github.com/poweradmin/poweradmin/actions/workflows/php.yml)
[![license](https://img.shields.io/badge/license-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![php version](https://img.shields.io/badge/php-8.2%2B-blue)](https://www.php.net/)
[![docker pulls](https://img.shields.io/docker/pulls/poweradmin/poweradmin)](https://hub.docker.com/r/poweradmin/poweradmin)
[![docker image size](https://img.shields.io/docker/image-size/poweradmin/poweradmin)](https://hub.docker.com/r/poweradmin/poweradmin)

[Poweradmin](https://www.poweradmin.org) is a DNS administration tool for PowerDNS that can be driven through a friendly web UI, a REST API, or both at the same time. Use the UI for day-to-day operations, the API for scripts and infrastructure-as-code, or run completely headless after the initial setup - the same validation runs on every path. It can work directly against the PowerDNS database (with API-assisted DNSSEC) or run entirely through the PowerDNS API in API backend mode.

## Features

- Supports all zone types (master, native, and slave)
- Supermasters for automatic provisioning of slave zones
- Zone templates for quick zone creation
- Bulk operations for records and reverse DNS
- Zone metadata editor for PowerDNS `domainmetadata`, including multi-value metadata kinds
- Native PowerDNS API backend mode - manage zones without direct access to the PowerDNS database
- Version-aware interface that adapts record types, metadata kinds, and terminology to the connected PowerDNS version
- IPv6 support
- Multi-language support (28 languages)
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

![Login interface with multi-language support](https://docs.poweradmin.org/screenshots/login.png)

### Dashboard

![Dashboard with quick actions and navigation](https://docs.poweradmin.org/screenshots/dashboard.png)

### Zone Management

![Zone list with sorting and filtering](https://docs.poweradmin.org/screenshots/zone-list.png)

### Zone Editor

![Zone editor with inline record management](https://docs.poweradmin.org/screenshots/zone-editor.png)

### Zone Metadata Editor

Poweradmin includes a zone metadata editor for PowerDNS `domainmetadata`. The editor supports:

- selecting known metadata kinds with inline guidance
- entering custom metadata kinds when needed
- multi-value metadata such as `ALLOW-AXFR-FROM` using one row per value

## Installation

For detailed installation instructions, please visit [the official documentation](https://docs.poweradmin.org/installation/).

### Traditional Installation

* **Recommended method - via releases**:
    * Get the latest stable release from [releases](https://github.com/poweradmin/poweradmin/releases)
* **For specific needs - via Git**:
    * **Warning**: The master branch is used for development of the next major release and may be unstable. For production use, stick with the `release/4.3.x` branch or a specific version tag (e.g. `v4.3.4`), or use the `stable` Docker tag.

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

**Want to drive PowerDNS from scripts instead of a browser?** Add `-e PA_API_ENABLED=true -e PA_API_DOCS_ENABLED=true` and follow the [Headless / API-First Quickstart](https://docs.poweradmin.org/getting-started/headless-quickstart/) - zero to scripted record updates in about five minutes.

* **Docker Hub**: `poweradmin/poweradmin`
* **GitHub Container Registry**: `ghcr.io/poweradmin/poweradmin`
* **Full documentation**: [DOCKER.md](DOCKER.md)
* **Security with Docker Secrets**: [DOCKER-SECRETS.md](DOCKER-SECRETS.md)

Features: Multi-database support (SQLite, MySQL, PostgreSQL), Docker secrets integration, FrankenPHP for enhanced performance.

## Requirements

* PHP 8.2 or higher (including 8.3, 8.4, 8.5, etc.)
* PHP extensions: intl, gettext, openssl, filter, tokenizer, pdo, xml, pdo-mysql/pdo-pgsql/pdo-sqlite, ldap (optional)
* MySQL 5.7.x/8.x, MariaDB, PostgreSQL or SQLite database
* PowerDNS authoritative server 4.0.0+ (including 4.x and 5.x series)

## Tested on

**Officially tested versions:**
- **master (4.4.x)**: PHP 8.2, PowerDNS 4.9.12, MariaDB 10.11, PostgreSQL 16.11
- **release/4.3.x (stable)**: PHP 8.2, PowerDNS 4.9.12, MariaDB 10.11, PostgreSQL 16.11
- **release/4.2.x (maintenance)**: PHP 8.2, PowerDNS 4.9.12, MariaDB 10.11, PostgreSQL 16.11
- **release/3.x (LTS)**: PHP 8.1, PowerDNS 4.7.4, MariaDB 10.11, MySQL 9.1, PostgreSQL 16.3, SQLite 3.45

**User-reported compatibility:**
- PowerDNS 4.8.x, 4.9.x, and 5.0.x series have been reported to work correctly by community users

**Compatibility note:** In the default SQL backend, Poweradmin operates primarily at the database level with PowerDNS, using the PowerDNS API for DNSSEC operations - the database schema stays relatively stable between PowerDNS releases, so compatibility is broad. In API backend mode, all operations go through the PowerDNS HTTP API instead. Since 4.4.0, the interface also detects the connected PowerDNS version and adjusts the available features accordingly.

## Version Support

Poweradmin maintains multiple release branches:

| Branch | Status | Support |
|--------|--------|---------|
| `develop` | Experimental | 4.5.x experimental features, may be unstable |
| `master` | Current release | 4.4.x releases - newest line, still hardening |
| `release/4.3.x` | Stable | Current stable line (recommended), patch releases and security updates |
| `release/4.2.x` | Maintenance | Security updates only, winding down |
| `release/4.1.x` | End of support | No further updates - upgrade to 4.3.x |
| `release/4.0.x` | End of support | No further updates - upgrade to 4.3.x |
| `release/3.x` | LTS | Bug fixes and security updates until December 2027 |

### PHP Version Support

**Important:** Starting with version 4.2.x, the minimum required PHP version is **8.2**. PHP 8.1 is no longer supported.

Poweradmin tracks the [official PHP release lifecycle](https://www.php.net/supported-versions.php). PHP versions that have reached end-of-life are dropped from the next Poweradmin release; security-only versions remain supported until then. See [docs.poweradmin.org → Requirements](https://docs.poweradmin.org/getting-started/requirements/) for the current supported range.

### Long-Term Support (LTS)

The **3.9.x branch** is designated as Long-Term Support (LTS), starting with version 3.9.8. This branch will receive bug fixes and security updates for at least two years, providing a stable option for organizations that prefer stability over immediate upgrades.

For more details, see the [Poweradmin in 2025: Year in Review](https://www.poweradmin.org/p/poweradmin-in-2025-year-in-review) blog post.

## Contributing

We welcome contributions to Poweradmin! As the sole maintainer of this non-profit project, I work alongside our amazing [contributors](https://github.com/poweradmin/poweradmin/graphs/contributors). See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## Support the Project

Poweradmin is independently developed and maintained. Your support helps keep the project alive and growing.

[![JetBrains logo.](https://resources.jetbrains.com/storage/products/company/brand/logos/jetbrains.svg)](https://jb.gg/OpenSourceSupport)

JetBrains provides IDE licenses used for development of this project.

### Organizations Supporting Development

<table>
  <tr>
    <td align="center" width="200">
      <a href="https://www.pyur.com/business">
        <img src="https://docs.poweradmin.org/img/sponsors/pyur.svg" alt="PYUR" height="40">
      </a>
      <br>HLkomm Telekommunikations GmbH
    </td>
    <td align="center" width="200">
      <a href="https://iram-institute.org/">
        <img src="https://docs.poweradmin.org/img/sponsors/iram.svg" alt="IRAM" height="40">
      </a>
      <br>IRAM
    </td>
    <td align="center" width="200">
      <a href="https://www.stepping-stone.ch/">
        <img src="https://docs.poweradmin.org/img/sponsors/stepping-stone.svg" alt="stepping stone AG" height="40">
      </a>
      <br>stepping stone AG
    </td>
    <td align="center" width="200">
      <a href="https://vistec.net/">
        <img src="https://docs.poweradmin.org/img/sponsors/vistec.png" alt="VISTEC Internet Service GmbH" height="40">
      </a>
      <br>VISTEC Internet Service GmbH
    </td>
    <td align="center" width="200">
      <a href="https://www.ybaca.net/">
        <img src="https://docs.poweradmin.org/img/sponsors/ybaca.svg" alt="yBaca s.r.o." height="40">
      </a>
      <br>yBaca s.r.o.
    </td>
  </tr>
</table>

### Individual Donors

* Stefano Rizzetto
* Asher Manangan
* Michiel Visser
* Gino Cremer
* Arthur Mayer
* Dylan Blanqué
* Tony Johnson
* Deeefje

For feature sponsorship, to speed up development of specific features, or to discuss ideas and issues, please [contact me](https://github.com/edmondas). Donations via invoice are also possible for organizations within the EU.

## Related Projects

* [terraform-provider-poweradmin](https://github.com/poweradmin/terraform-provider-poweradmin) - Terraform/OpenTofu provider for managing DNS zones and records through Poweradmin
* [certbot-dns-poweradmin](https://github.com/poweradmin/certbot-dns-poweradmin) - Certbot DNS plugin for Poweradmin to automate Let's Encrypt certificate issuance with DNS-01 challenge
* [external-dns-poweradmin-webhook](https://github.com/poweradmin/external-dns-poweradmin-webhook) - ExternalDNS webhook provider for Poweradmin to synchronize Kubernetes DNS records
* [cert-manager-webhook-poweradmin](https://github.com/poweradmin/cert-manager-webhook-poweradmin) - cert-manager webhook solver for Poweradmin to automate DNS-01 challenge validation

## Note

Poweradmin is an independent community project, not affiliated with [PowerDNS.com](https://www.powerdns.com/index.html) or [Open-Xchange](https://www.open-xchange.com).

## License

This project is licensed under the GNU General Public License v3.0. See the LICENSE file for more details.
