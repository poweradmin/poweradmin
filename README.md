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

This project is not associated with [PowerDNS.com](https://www.powerdns.com/index.html)
, [Open-Xchange](https://www.open-xchange.com), or any other external parties.
It is independently funded and maintained. If this project does not fulfill your requirements, please explore these
alternative [options](https://github.com/PowerDNS/pdns/wiki/WebFrontends).

## License

This project is licensed under the GNU General Public License v3.0. See the LICENSE file for more details.

## Supported by

[![JetBrains logo.](https://resources.jetbrains.com/storage/products/company/brand/logos/jetbrains.svg)](https://jb.gg/OpenSourceSupport)

## Requirements

* PHP 8.1 or higher (including 8.2, 8.3, 8.4, etc.)
* PHP intl extension
* PHP gettext extension
* PHP openssl extension
* PHP filter extension
* PHP tokenizer extension
* PHP pdo extension
* PHP pdo-mysql, pdo-pgsql or pdo-sqlite extension
* PHP ldap extension (optional)
* MySQL 5.7.x/8.x, MariaDB, PostgreSQL or SQLite database
* PowerDNS authoritative server 4.0.0+

## Tested on

| Poweradmin | PHP            | PowerDNS | MariaDB  | MySQL  | PostgreSQL | SQLite |
|------------|----------------|----------|----------|--------|------------|--------|
| 3.9.x      | 8.1.31         | 4.7.4    | 10.11.10 | -      | 16.3       | 3.45.3 |
| 3.8.x      | 8.1.28         | 4.5.5    | 10.11.8  | -      | 16.3       | 3.45.3 |
| 3.7.x      | 8.1.2          | 4.5.3    | 11.1.2   | 8.2.0  | 16.0       | 3.40.1 |
| 3.6.x      | 8.1.2          | 4.5.3    | 11.1.2   | 8.1.0  | 16.0       | 3.40.1 |
| 3.5.x      | 8.1.17         | 4.5.3    | 10.11.2  | 8.0.32 | 15.2       | 3.34.1 |
| 3.4.x      | 7.4.3 / 8.1.12 | 4.2.1    | 10.10.2  | 8.0.31 | 15.1       | 3.34.1 |

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

* Via Git:
    * Clone the repository: ```git clone https://github.com/poweradmin/poweradmin.git```
    * Change directory to the cloned repository: ```cd poweradmin```
    * Select the latest stable tag (for example v3.8.1): ```git checkout tags/v3.8.1```
    * Alternatively, you can use the master branch, but it might be unstable as it is used for development:
      ```git checkout master```
* Via releases:
    * Get the latest file from [releases](https://github.com/poweradmin/poweradmin/releases)

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
With the above NGINX configuration, make sure to also move all the files in the Poweradmin (repository) directory to the root declared in the configuration (in the example: `root /var/www/html;`). Once this is done, reload your NGINX installation and it should be applied.
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

### Debug Settings

To help diagnose issues, you can enable various debug settings in the `inc/config.inc.php` file. Below are the available
debug settings and how to enable them:

1. **PHP Error Reporting**: To display PHP errors directly in the browser, add the following lines to your `index.php`
   or any other entry point file:
    ```php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    ```

2. **Logger Settings**: Configure the logging settings to use the native type and debug level. Currently, these settings
   are used only for logging authentication issues:
    ```php
    $logger_type = 'native';
    $logger_level = 'debug';
    ```

3. **Database Debugging**: Enable or disable database debugging. When enabled, detailed database operations and errors
   will be logged:
    ```php
    $db_debug = true;
    ```

4. **DNSSEC Debugging**: Enable or disable DNSSEC debugging. When enabled, detailed DNSSEC operations and errors will be
   logged:
    ```php
    $pdnssec_debug = true;
    ```

5. **LDAP Debugging**: Enable or disable LDAP debugging. When enabled, detailed LDAP operations and errors will be
   logged:
    ```php
    $ldap_debug = true;
    ```

By enabling these settings, you can gain more insight into the application's behavior and troubleshoot issues more
effectively.

## Contributing

### Steps to Contribute

1. **Fork the Repository**: Use the "Fork" button on the repository page to create a copy of the repository under your
   GitHub account.
2. **Clone the Forked Repository**: Download the forked repository to your local machine.
3. **Create a New Branch**: Create a new branch for your feature or bugfix.
4. **Make Changes**: Make your changes to the codebase.
5. **Commit Changes**: Save your changes with a descriptive commit message.
6. **Push Changes**: Upload your changes to your forked repository.
7. **Create a Pull Request**: Go to the original repository and create a pull request from your forked repository.

### Partial Acceptance

Please note that while I am open to contributions, I might only take the good parts of your submission or rewrite it to
keep in sync with the overall style and structure of the project. However, I will still keep a reference to you as the
original contributor. This will be mentioned in the release notes.

### Code Review

All contributions will be reviewed. Feedback will be provided, and you may be asked to make additional changes.

### Testing

Ensure that your changes are well-tested.

Thank you for your contributions!
