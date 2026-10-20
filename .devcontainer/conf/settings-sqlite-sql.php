<?php

/**
 * Poweradmin devcontainer settings: SQLite + SQL backend
 *
 * Shared values come from settings-base.php and settings-sqlite.php.
 */

require_once is_file(__DIR__ . '/settings-base.php') ? __DIR__ . '/settings-base.php' : '/app/.devcontainer/conf/settings-base.php';

$settings = devcontainer_settings('sqlite');

$settings['interface']['title'] = 'Poweradmin (SQLite + SQL)';

return $settings;
