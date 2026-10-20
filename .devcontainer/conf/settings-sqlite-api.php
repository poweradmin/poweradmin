<?php

/**
 * Poweradmin devcontainer settings: SQLite + PowerDNS API backend (experimental)
 *
 * Shared values come from settings-base.php and settings-sqlite.php.
 */

require_once is_file(__DIR__ . '/settings-base.php') ? __DIR__ . '/settings-base.php' : '/app/.devcontainer/conf/settings-base.php';

$settings = devcontainer_settings('sqlite');
$settings = devcontainer_api_backend($settings);

$settings['interface']['title'] = 'Poweradmin (SQLite + API)';
// Own database file and own PowerDNS server, so this instance and the SQL one
// never contend for the same SQLite writer lock.
$settings['database']['file'] = '/data/pdns-api.db';
$settings['pdns_api']['url'] = 'http://pdns-sqlite-api:8081';
// The sweep runs the full suite here, and the layout specs assert the default
// chrome; the modern theme is exercised by the mysql-sql-de instance instead.

return $settings;
