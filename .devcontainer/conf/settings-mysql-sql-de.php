<?php

/**
 * Poweradmin devcontainer settings: MySQL + SQL backend, German interface only (single-language testing)
 *
 * Shared values come from settings-base.php and settings-mysql.php.
 */

require_once is_file(__DIR__ . '/settings-base.php') ? __DIR__ . '/settings-base.php' : '/app/.devcontainer/conf/settings-base.php';

$settings = devcontainer_settings('mysql');

$settings['interface']['language'] = 'de_DE';
$settings['interface']['enabled_languages'] = 'de_DE';
$settings['interface']['title'] = 'Poweradmin (MySQL + SQL, German)';
$settings['interface']['theme'] = 'modern';

return $settings;
