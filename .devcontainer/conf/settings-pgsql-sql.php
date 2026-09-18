<?php

/**
 * Poweradmin devcontainer settings: PostgreSQL + SQL backend (Apache instance)
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

$settings['interface']['title'] = 'Poweradmin (PostgreSQL + SQL)';
$settings['pdns_api']['url'] = 'http://pdns-pgsql:8081';
$settings['ldap']['enabled'] = false;

return $settings;
