<?php

/**
 * Poweradmin devcontainer settings: MySQL + SQL backend served under /poweradmin (subfolder deployment)
 *
 * Shared values come from settings-base.php and settings-mysql.php.
 */

require_once is_file(__DIR__ . '/settings-base.php') ? __DIR__ . '/settings-base.php' : '/app/.devcontainer/conf/settings-base.php';

$settings = devcontainer_settings('mysql');

$settings['security']['session_key'] = 'subfolder_session_key_for_testing_only_12345';
$settings['security']['mfa']['enabled'] = false;
$settings['interface']['title'] = 'Poweradmin (MySQL + Subfolder)';
$settings['interface']['base_url_prefix'] = '/poweradmin';

return $settings;
