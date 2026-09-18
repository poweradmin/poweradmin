<?php

/**
 * Poweradmin devcontainer settings: MySQL + SQL backend served under /poweradmin (subfolder deployment)
 *
 * Everything not set here comes from settings-base.php (mounted next to this file).
 */

// The base sits next to this file in the repo; inside a container only the instance file is
// mounted, so fall back to the repo checkout mounted at /app.
$base = is_file(__DIR__ . '/settings-base.php')
    ? __DIR__ . '/settings-base.php'
    : '/app/.devcontainer/conf/settings-base.php';
$settings = require $base;

$settings['security']['session_key'] = 'subfolder_session_key_for_testing_only_12345';
$settings['security']['mfa']['enabled'] = false;
$settings['interface']['title'] = 'Poweradmin (MySQL + Subfolder)';
$settings['interface']['base_url_prefix'] = '/poweradmin';

return $settings;
