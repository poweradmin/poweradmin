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
$settings['interface']['theme'] = 'modern';

return $settings;
