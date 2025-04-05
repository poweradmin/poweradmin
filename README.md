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
- **4.0.x (development)**: PHP 8.1.31, PowerDNS 4.7.4, MariaDB 10.11.10, MySQL 9.1.0, PostgreSQL 16.3, SQLite 3.45.3
- **3.9.x (stable)**: PHP 8.1.31, PowerDNS 4.7.4, MariaDB 10.11.10, MySQL 9.1.0, PostgreSQL 16.3, SQLite 3.45.3

## Installation

To install Poweradmin onto your system there are a few dependencies, they are listed below.

On Debian-based systems and their derivatives (with sudo or as root, Debian 12 or later recommended):
> **Note:** `php-fpm` is required only if you plan to use Nginx or choose not to use `mod_php` with Apache.

```sh
apt install php php-intl php-php-gettext php-tokenizer php-fpm

#For MySQL/MariaDB
apt install php-mysql

#For PostgreSQL
apt install php-pgsql

#For SQLite
apt install php-sqlite3
```

On Red Hat Enterprise Linux (RHEL) and its derivatives:

```sh
dnf install -y php php-intl php-gettext php-pdo php-fpm

#For MySQL/MariaDB
dnf install -y php-mysqlnd

#For PostgreSQL
dnf install -y php-pgsql
```

To get Poweradmin working on your preferred webserver (Apache/NGINX for example), download the source-code from GitHub.
Note that Ubuntu has an Apache server by default, so the following NGINX configuration is only needed for Debian or
custom installations:

* **Recommended method - via releases**:
    * Get the latest stable release from [releases](https://github.com/poweradmin/poweradmin/releases)
* **For specific needs - via Git**:
    * Clone the repository: ```git clone https://github.com/poweradmin/poweradmin.git```
    * Change directory to the cloned repository: ```cd poweradmin```
    * Select the latest stable tag (for example v3.9.0): ```git checkout tags/v3.9.0```
    * ⚠️ **Warning**: The master branch (4.0.x) is used for development and may be unstable. For production use, stick with the stable 3.9.x release.

For NGINX create a configuration file that looks like this (done on Debian), of course adjust values to your liking:

```
server {
    listen 80;
    server_name localhost;

    root /var/www/html;
    index index.php index.html index.htm;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to .htaccess and .htpasswd files for security reasons
    location ~ /\.ht {
        deny all;
    }
}
```

With the above NGINX configuration, make sure to also move all the files in the Poweradmin (repository) directory to the
root declared in the configuration (in the example: `root /var/www/html;`). Once this is done, reload your NGINX
installation and it should be applied.
Then you can navigate to the installed system in your browser

> **Note:** You can also safely remove the default index.html (or derivative).

* Visit http(s)://HOSTNAME/install/ and follow the installation steps.
* Once the installation is complete, remove the `install` folder.
* Point your browser to: http(s)://URL
* Log in using the default 'admin' username and the password created during setup (provided in step 3).

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
