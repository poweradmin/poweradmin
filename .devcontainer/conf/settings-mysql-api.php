<?php

/**
 * Poweradmin devcontainer settings: MySQL + PowerDNS API backend (experimental)
 *
 * Shared values come from settings-base.php and settings-mysql.php.
 */

require_once is_file(__DIR__ . '/settings-base.php') ? __DIR__ . '/settings-base.php' : '/app/.devcontainer/conf/settings-base.php';

$settings = devcontainer_settings('mysql');
$settings = devcontainer_api_backend($settings);

$settings['interface']['title'] = 'Poweradmin (MySQL + API)';
// Own databases and own PowerDNS server, so this instance and the SQL one never
// write to the same zones, domains or records rows.
$settings['database']['name'] = 'poweradmin_api';
$settings['database']['pdns_db_name'] = 'pdns_api';
$settings['pdns_api']['url'] = 'http://pdns-mysql-api:8081';

return $settings;
