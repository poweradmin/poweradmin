<?php

/**
 * Poweradmin devcontainer settings: MySQL + SQL backend behind nginx basic auth, signing
 * users in from REMOTE_USER (web server authentication)
 *
 * Shared values come from settings-base.php and settings-mysql.php.
 */

require_once is_file(__DIR__ . '/settings-base.php') ? __DIR__ . '/settings-base.php' : '/app/.devcontainer/conf/settings-base.php';

$settings = devcontainer_settings('mysql');

$settings['interface']['title'] = 'Poweradmin (MySQL + SQL, web server login)';
$settings['remote_user']['enabled'] = true;
// The devcontainer database has no 'Guest' template
$settings['remote_user']['default_permission_template'] = 'Read Only';

return $settings;
