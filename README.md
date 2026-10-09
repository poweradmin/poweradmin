# Poweradmin

[![release](https://img.shields.io/github/v/release/poweradmin/poweradmin)](https://github.com/poweradmin/poweradmin/releases)
[![validations](https://github.com/poweradmin/poweradmin/actions/workflows/php.yml/badge.svg)](https://github.com/poweradmin/poweradmin/actions/workflows/php.yml)
[![license](https://img.shields.io/badge/license-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![php version](https://img.shields.io/badge/php-8.2%2B-blue)](https://www.php.net/)
[![docker pulls](https://img.shields.io/docker/pulls/poweradmin/poweradmin)](https://hub.docker.com/r/poweradmin/poweradmin)
[![docker image size](https://img.shields.io/docker/image-size/poweradmin/poweradmin)](https://hub.docker.com/r/poweradmin/poweradmin)

[Poweradmin](https://www.poweradmin.org) is a DNS administration tool for PowerDNS that can be driven through a friendly web UI, a REST API, or both at the same time. Use the UI for day-to-day operations, the API for scripts and infrastructure-as-code, or run completely headless after the initial setup - the same validation runs on every path. It can work directly against the PowerDNS database (with API-assisted DNSSEC) or run entirely through the PowerDNS API in API backend mode.

**DNSSEC-safe via the PowerDNS API.** In API backend mode (4.3.0+) every change goes through the PowerDNS REST API, so PowerDNS itself keeps NSEC/NSEC3 chains correct and Poweradmin needs no access to its database. With the default SQL backend, set the PowerDNS API URL and key, and Poweradmin will rectify signed zones through the API after each change it makes. See [PowerDNS API](https://docs.poweradmin.org/configuration/powerdns-api/) and [DNSSEC](https://docs.poweradmin.org/configuration/dnssec/).

![Zone editor with inline record management](https://docs.poweradmin.org/screenshots/zone-editor.png)

```bash
docker run -d --name poweradmin -p 8080:80 -e DB_TYPE=sqlite -e PA_CREATE_ADMIN=1 poweradmin/poweradmin:latest
```

## Features

- All zone types (master, native, slave, producer and consumer), catalog zone membership, supermasters, and zone templates
- DNSSEC-safe via the PowerDNS API - API backend mode needs no direct access to the PowerDNS database, and the SQL backend rectifies signed zones through the API
- Version-aware interface that adapts record types, metadata kinds, and terminology to the connected PowerDNS version
- DNSSEC operations, plus a zone metadata editor for PowerDNS `domainmetadata`
- REST API with OpenAPI documentation, and API keys that can be made read-only, restricted to specific operations, or scoped to specific zones
- Bulk operations for records and reverse DNS, and secondary zone import over AXFR
- Record change log with before/after snapshots of every record and zone change
- Users, groups, and role-based permissions, with LDAP, SAML, OIDC, and TOTP two-factor authentication
- 43 languages including right-to-left, light and dark themes, and full IPv6 support
- Docker deployment with FrankenPHP

[Full feature list](https://docs.poweradmin.org/getting-started/features/)

## Automation

Poweradmin's REST API is wrapped by ready-made integrations, so zones can be managed from infrastructure tooling rather than a browser:

* [terraform-provider-poweradmin](https://github.com/poweradmin/terraform-provider-poweradmin) - Terraform/OpenTofu provider for zones and records
* [external-dns-poweradmin-webhook](https://github.com/poweradmin/external-dns-poweradmin-webhook) - ExternalDNS webhook provider for Kubernetes
* [cert-manager-webhook-poweradmin](https://github.com/poweradmin/cert-manager-webhook-poweradmin) - cert-manager solver for DNS-01 challenges
* [certbot-dns-poweradmin](https://github.com/poweradmin/certbot-dns-poweradmin) - Certbot plugin for Let's Encrypt DNS-01 challenges

## Screenshots

### Login Screen

![Login interface with multi-language support](https://docs.poweradmin.org/screenshots/login.png)

### Dashboard

![Dashboard with quick actions and navigation](https://docs.poweradmin.org/screenshots/dashboard.png)

### Zone Management

![Zone list with sorting and filtering](https://docs.poweradmin.org/screenshots/zone-list.png)

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
    * **Warning**: The master branch carries the next release and may be unstable. For production use, stick with the `release/4.4.x` LTS branch or a specific version tag (e.g. `v4.4.1`), or pin the matching Docker tag (e.g. `4.4.1`).

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
* **Security with Docker Secrets**: [Docker Secrets](https://docs.poweradmin.org/installation/docker-secrets/)

Features: Multi-database support (SQLite, MySQL, PostgreSQL), Docker secrets integration, FrankenPHP for enhanced performance.

## Requirements

* PHP 8.2 or higher (including 8.3, 8.4, 8.5, etc.)
* PHP extensions: intl, gettext, openssl, filter, tokenizer, pdo, xml, pdo-mysql/pdo-pgsql/pdo-sqlite, ldap (optional)
* MySQL 5.7.x/8.x, MariaDB, PostgreSQL or SQLite database
* PowerDNS Authoritative Server 4.x or 5.x, tested from 4.5 (see [Platform Lifecycle](https://docs.poweradmin.org/getting-started/lifecycle/) for support dates)

## Tested on

**Officially tested versions:**
- **develop (4.6.0)**: PHP 8.2-8.5, PowerDNS 5.1.4, MariaDB 10.11, PostgreSQL 16.11
- **master (4.5.x)**: PHP 8.2, PowerDNS 5.1.4, MariaDB 10.11, PostgreSQL 16.11
- **release/4.4.x (LTS)**: PHP 8.2, PowerDNS 4.9.12, MariaDB 10.11, PostgreSQL 16.11
- **release/4.3.x (maintenance)**: PHP 8.2, PowerDNS 4.9.12, MariaDB 10.11, PostgreSQL 16.11
- **release/4.2.x (maintenance)**: PHP 8.2, PowerDNS 4.9.12, MariaDB 10.11, PostgreSQL 16.11
- **release/3.x (LTS)**: PHP 8.1, PowerDNS 4.7.4, MariaDB 10.11, MySQL 9.1, PostgreSQL 16.3, SQLite 3.45

**Other PowerDNS versions:** the development environment can also run PowerDNS 4.5, 4.6, 4.7, 4.8, 4.9 and 5.0 (`PDNS_VERSION` in `.devcontainer/.env`). Versions older than 4.5 are not tested. Many stable distributions still ship PowerDNS 4.x, so 4.x support stays.

**Compatibility note:** In the default SQL backend, Poweradmin operates primarily at the database level with PowerDNS, using the PowerDNS API for DNSSEC operations - the database schema stays relatively stable between PowerDNS releases, so compatibility is broad. In API backend mode, all operations go through the PowerDNS HTTP API instead. Since 4.4.0, the interface also detects the connected PowerDNS version and adjusts the available features accordingly.

## Version Support

| Version | Status |
|---------|--------|
| 4.4.x | LTS until December 2027, recommended for production. Last line with API v1 |
| 4.3.x, 4.2.x | Last fixes only, end of life three months after the 4.5.0 release |
| 3.9.x | LTS until December 2027 |
| 4.1.x, 4.0.x, 3.8.x and older | End of life, please upgrade |

`master` holds 4.5.0 and `develop` holds 4.6.0 development. The support rules, a timeline, and the PHP, PowerDNS and distribution support dates are on [Version Support](https://docs.poweradmin.org/getting-started/lifecycle/).

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
* yBaca s.r.o.
* Stephan Gogler

For feature sponsorship, to speed up development of specific features, or to discuss ideas and issues, please [contact me](https://github.com/edmondas). Donations are accepted through [GitHub Sponsors](https://github.com/sponsors/edmondas), [Open Collective](https://opencollective.com/poweradmin) and [PayPal](https://paypal.me/egirkantas). Companies can receive an invoice through Open Collective from anywhere, or directly for organizations within the EU.

## Community Projects

* [poweradmin-cli](https://github.com/Contentways/poweradmin-cli) - Command-line interface for managing zones, records, users and groups through the Poweradmin REST API
* [poweradmin-go](https://github.com/Contentways/poweradmin-go) - Go SDK for the Poweradmin REST API
* [poweradmin-operator](https://github.com/Contentways/poweradmin-operator) - Kubernetes operator for managing DNS zones and records via the Poweradmin API

## Note

Poweradmin is an independent community project, not affiliated with [PowerDNS.com](https://www.powerdns.com/index.html) or [Open-Xchange](https://www.open-xchange.com).

## License

This project is licensed under the GNU General Public License v3.0. See the LICENSE file for more details.
