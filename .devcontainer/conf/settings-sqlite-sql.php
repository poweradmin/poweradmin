<?php

/**
 * Poweradmin devcontainer settings: SQLite + SQL backend
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
  'type' => 'sqlite',
  'file' => '/data/pdns.db',
];

$settings['interface']['enabled_languages'] = 'en_EN,de_DE,fr_FR,ja_JP,pl_PL';
$settings['interface']['title'] = 'Poweradmin (SQLite + SQL)';
$settings['pdns_api']['url'] = 'http://pdns-sqlite:8081';
$settings['ldap']['enabled'] = false;

return $settings;
