# Poweradmin

[![release](https://img.shields.io/github/v/release/poweradmin/poweradmin)](https://github.com/poweradmin/poweradmin/releases)
[![validations](https://github.com/poweradmin/poweradmin/actions/workflows/php.yml/badge.svg)](https://github.com/poweradmin/poweradmin/actions/workflows/php.yml)
[![license](https://img.shields.io/badge/license-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![php version](https://img.shields.io/badge/php-8.1%2B-blue)](https://www.php.net/)

[Poweradmin](https://www.poweradmin.org) is a friendly web-based DNS administration tool for PowerDNS server. The
interface supports most of
the features of PowerDNS. It is a hybrid solution that uses SQL for most operations and has PowerDNS API support for
DNSSEC operations.

**This is the Long-Term Support (LTS) branch.** For new features, see the [4.x release branch](https://github.com/poweradmin/poweradmin/tree/release/4.x).

## Features

- Supports all zone types (master, native, and slave)
- Supermasters for automatic provisioning of slave zones
- IPv6 support
- Multi-language support
- DNSSEC operations
- Light and dark themes
- Ability to add reverse records
- LDAP authentication support with custom filter

## Screenshots

### Login

![The login screen](https://docs.poweradmin.org/screenshots/ignite_login.png)

### Zone List

![List of zones](https://docs.poweradmin.org/screenshots/ignite_zone_list.png)

## Requirements

* PHP 8.1 or higher (including 8.2, 8.3, 8.4, etc.)
* PHP extensions: intl, gettext, openssl, filter, tokenizer, pdo, pdo-mysql/pdo-pgsql/pdo-sqlite, ldap (optional)
* MySQL 5.7.x/8.x, MariaDB, PostgreSQL or SQLite database
* PowerDNS authoritative server 4.0.0+ (including 4.x and 5.x series)

## Tested on

**Officially tested versions (3.x LTS branch):**

| Poweradmin | PHP    | PowerDNS | MariaDB  | MySQL | PostgreSQL | SQLite |
|------------|--------|----------|----------|-------|------------|--------|
| 3.9.x      | 8.1.31 | 4.7.4    | 10.11.10 | 9.1.0 | 16.3       | 3.45.3 |
| 3.8.x      | 8.1.28 | 4.5.5    | 10.11.8  | -     | 16.3       | 3.45.3 |
| 3.7.x      | 8.1.2  | 4.5.3    | 11.1.2   | 8.2.0 | 16.0       | 3.40.1 |

**User-reported compatibility:**
- PowerDNS 4.8.x, 4.9.x, and 5.0.x series have been reported to work correctly by community users

**Compatibility note:** Poweradmin operates primarily at the database level with PowerDNS, using the PowerDNS API only for DNSSEC operations. This design provides broad compatibility across PowerDNS versions, as the database schema remains relatively stable between releases.

## Version Support

Poweradmin maintains multiple release branches:

| Branch | Status | Support |
|--------|--------|---------|
| `master` | Development | Experimental features, unstable |
| `release/4.x` | Stable | Current release with new features |
| `release/3.x` | **LTS** | Bug fixes and security updates for at least 2 years |

### Long-Term Support (LTS)

The **3.x branch** is designated as Long-Term Support (LTS). This branch will receive bug fixes and security updates for at least two years, providing a stable option for organizations that prefer stability over immediate upgrades to the 4.x series.

**LTS Guidelines:**
- No breaking changes - all updates are backwards compatible
- Bug fixes and security updates only
- No new features that could destabilize the codebase
- PHP 8.1, 8.2, 8.3, and 8.4 compatibility maintained

## Installation

For detailed installation instructions, please visit [the official documentation](https://docs.poweradmin.org/installation/).

### Quick Start

On Debian-based systems (Debian 12 or later recommended):

```sh
apt install php php-intl php-php-gettext php-tokenizer php-fpm

# For MySQL/MariaDB
apt install php-mysql

# For PostgreSQL
apt install php-pgsql

# For SQLite
apt install php-sqlite3
```

On Red Hat Enterprise Linux (RHEL) and derivatives:

```sh
dnf install -y php php-intl php-gettext php-pdo php-fpm

# For MySQL/MariaDB
dnf install -y php-mysqlnd

# For PostgreSQL
dnf install -y php-pgsql
```

### Download

* **Recommended - via releases**:
    * Get the latest stable release from [releases](https://github.com/poweradmin/poweradmin/releases)
* **Via Git**:
    * Clone: `git clone https://github.com/poweradmin/poweradmin.git`
    * Switch to LTS branch: `git checkout release/3.x`

### Setup

1. Visit `http(s)://HOSTNAME/install/` and follow the installation steps
2. Once installation is complete, remove the `install` folder
3. Log in using the 'admin' username and the password created during setup

## Debug Settings

To help diagnose issues, you can enable various debug settings in the `inc/config.inc.php` file:

```php
// PHP Error Reporting (add to index.php)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Logger Settings (authentication issues)
$logger_type = 'native';
$logger_level = 'debug';

// Database Debugging
$db_debug = true;

// DNSSEC Debugging
$pdnssec_debug = true;

// LDAP Debugging
$ldap_debug = true;
```

## Contributing

We welcome contributions to Poweradmin! See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

**Note for LTS branch:** Contributions to the 3.x branch should focus on bug fixes and security updates only. New features should be submitted to the master branch.

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

For feature sponsorship or to discuss ideas, please [contact me](https://github.com/edmondas).

## Related Projects

* [terraform-provider-poweradmin](https://github.com/poweradmin/terraform-provider-poweradmin) - Terraform/OpenTofu provider for managing DNS zones and records
* [certbot-dns-poweradmin](https://github.com/poweradmin/certbot-dns-poweradmin) - Certbot DNS plugin for Let's Encrypt DNS-01 challenge
* [external-dns-poweradmin-webhook](https://github.com/poweradmin/external-dns-poweradmin-webhook) - ExternalDNS webhook for Kubernetes DNS records

## Note

Poweradmin is an independent community project, not affiliated with [PowerDNS.com](https://www.powerdns.com/index.html) or [Open-Xchange](https://www.open-xchange.com).

## License

This project is licensed under the GNU General Public License v3.0. See the LICENSE file for more details.
