<?php

/**
 * Poweradmin devcontainer settings: PostgreSQL + PowerDNS API backend (experimental)
 *
 * Shared values come from settings-base.php and settings-pgsql.php.
 */

require_once is_file(__DIR__ . '/settings-base.php') ? __DIR__ . '/settings-base.php' : '/app/.devcontainer/conf/settings-base.php';

$settings = devcontainer_settings('pgsql');
$settings = devcontainer_api_backend($settings);

$settings['interface']['title'] = 'Poweradmin (PostgreSQL + API)';
// Own database and own PowerDNS server, so this instance and the SQL one never
// write to the same zones, domains or records rows.
$settings['database']['name'] = 'pdns_api';
$settings['pdns_api']['url'] = 'http://pdns-pgsql-api:8081';

return $settings;
