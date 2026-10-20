<?php

/**
 * Poweradmin devcontainer settings: PostgreSQL + SQL backend (Apache instance)
 *
 * Shared values come from settings-base.php and settings-pgsql.php.
 */

require_once is_file(__DIR__ . '/settings-base.php') ? __DIR__ . '/settings-base.php' : '/app/.devcontainer/conf/settings-base.php';

$settings = devcontainer_settings('pgsql');

$settings['interface']['title'] = 'Poweradmin (PostgreSQL + SQL)';

return $settings;
