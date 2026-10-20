<?php

/**
 * Poweradmin devcontainer settings: MySQL + SQL backend (default)
 *
 * Shared values come from settings-base.php and settings-mysql.php.
 */

require_once is_file(__DIR__ . '/settings-base.php') ? __DIR__ . '/settings-base.php' : '/app/.devcontainer/conf/settings-base.php';

$settings = devcontainer_settings('mysql');

$settings['interface']['title'] = 'Poweradmin (MySQL + SQL)';

return $settings;
