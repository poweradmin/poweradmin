# Poweradmin [![Composer](https://github.com/poweradmin/poweradmin/actions/workflows/php.yml/badge.svg)](https://github.com/poweradmin/poweradmin/actions/workflows/php.yml)

[Poweradmin](https://www.poweradmin.org) is a friendly web-based DNS administration tool for PowerDNS server. The
interface supports most of
the features of PowerDNS. It is a hybrid solution that uses SQL for most operations and has PowerDNS API support for
DNSSEC operations.

## Features

- Supports all zone types (master, native, and slave)
- Supermasters for automatic provisioning of slave zones
- IPv6 support
- Multi-language support
- DNSSEC operations
- Light and dark themes
- Ability to add reverse records
- LDAP authentication support with custom filter

## Disclaimer

This project is not associated
with [PowerDNS.com](https://www.powerdns.com/index.html), [Open-Xchange](https://www.open-xchange.com), or any other
external parties.
It is independently funded and maintained. If this project does not fulfill your requirements, please explore these
alternative [options](https://github.com/PowerDNS/pdns/wiki/WebFrontends).

## License

This project is licensed under the GNU General Public License v3.0. See the LICENSE file for more details.

## Supported by

[![JetBrains logo.](https://resources.jetbrains.com/storage/products/company/brand/logos/jetbrains.svg)](https://jb.gg/OpenSourceSupport)

## Requirements

* PHP 8.1 or higher (including 8.2, 8.3, 8.4, etc.)
* PHP extensions: intl, gettext, openssl, filter, tokenizer, pdo, pdo-mysql/pdo-pgsql/pdo-sqlite, ldap (optional)
* MySQL 5.7.x/8.x, MariaDB, PostgreSQL or SQLite database
* PowerDNS authoritative server 4.0.0+

## Tested on

**Versions:**
- **4.0.x (development)**: PHP 8.2.28, PowerDNS 4.7.4, MariaDB 10.11.10, MySQL 9.1.0, PostgreSQL 16.3, SQLite 3.45.3
- **3.9.x (stable)**: PHP 8.1.31, PowerDNS 4.7.4, MariaDB 10.11.10, MySQL 9.1.0, PostgreSQL 16.3, SQLite 3.45.3

## Installation

For detailed installation instructions, please visit [the official documentation](https://docs.poweradmin.org/installation/).

### Traditional Installation

* **Recommended method - via releases**:
    * Get the latest stable release from [releases](https://github.com/poweradmin/poweradmin/releases)
* **For specific needs - via Git**:
    * ⚠️ **Warning**: The master branch (4.0.x) is used for development and may be unstable. For production use, stick with the stable 3.9.x release.

### Docker Deployment

🐳 **Quick Start with Docker**:
```bash
docker run -d \
  --name poweradmin \
  -p 8080:80 \
  -e DB_TYPE=sqlite \
  edmondas/poweradmin:latest
```

* **Docker Hub**: `edmondas/poweradmin`
* **GitHub Container Registry**: `ghcr.io/poweradmin/poweradmin`
* **Full documentation**: [DOCKER.md](DOCKER.md)
* **Security with Docker Secrets**: [DOCKER-SECRETS.md](DOCKER-SECRETS.md)

Features: Multi-database support (SQLite, MySQL, PostgreSQL), Docker secrets integration, FrankenPHP for enhanced performance.

## Screenshots

### Log in

![The login screen](https://docs.poweradmin.org/screenshots/ignite_login.png)

### Zone list

![List of zones](https://docs.poweradmin.org/screenshots/ignite_zone_list.png)

## Contributing

We welcome contributions to improve Poweradmin. If you'd like to contribute, please follow the standard GitHub workflow
of forking the repository, creating a branch, making changes, and submitting a pull request.

### Contribution Guidelines

1. **Code Quality**: Ensure your code follows the project's style and standards
2. **Testing**: Test your changes thoroughly before submitting
3. **Documentation**: Include appropriate documentation for new features

### Attribution Policy

All meaningful contributions are credited in release notes. Please note:

- Sometimes similar ideas come from multiple contributors; implementation quality determines which is merged
- Contributions may be partially accepted or rewritten to maintain project consistency
- Even if your exact code isn't used, your ideas will still be credited if they influence the final implementation

Please forgive me if I occasionally miss crediting someone in the release notes. Managing a project and preparing new
versions while tracking all contributions is challenging. If you notice your contribution hasn't been acknowledged,
please reach out - I'm always open to corrections and want to ensure everyone receives proper recognition.

Thank you for your contributions!
