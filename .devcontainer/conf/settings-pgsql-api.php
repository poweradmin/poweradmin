<?php

/**
 * Poweradmin devcontainer settings: PostgreSQL + PowerDNS API backend (experimental)
 *
 * Everything not set here comes from settings-base.php (mounted next to this file).
 */

// The base sits next to this file in the repo; inside a container only the instance file is
// mounted, so fall back to the repo checkout mounted at /app.
$base = is_file(__DIR__ . '/settings-base.php')
    ? __DIR__ . '/settings-base.php'
    : '/app/.devcontainer/conf/settings-base.php';
$settings = require $base;

$settings['database'] = [
  'host' => 'postgres',
  'port' => '5432',
  'name' => 'pdns',
  'user' => 'pdns',
  'password' => 'poweradmin',
  'type' => 'pgsql',
  'charset' => 'utf8',
];

$settings['interface']['title'] = 'Poweradmin (PostgreSQL + API)';
$settings['interface']['display_signed_serial_in_zone_list'] = true;
$settings['dns']['backend'] = 'api';
$settings['pdns_api']['url'] = 'http://pdns-pgsql:8081';
$settings['ldap']['enabled'] = false;
$settings['modules']['secondary_zone_import'] = [
  'enabled' => true,
];

return $settings;
