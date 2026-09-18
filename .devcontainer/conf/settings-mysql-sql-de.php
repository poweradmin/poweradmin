<?php

/**
 * Poweradmin devcontainer settings: MySQL + SQL backend, German interface only (single-language testing)
 *
 * Everything not set here comes from settings-base.php (mounted next to this file).
 */

// The base sits next to this file in the repo; inside a container only the instance file is
// mounted, so fall back to the repo checkout mounted at /app.
$base = is_file(__DIR__ . '/settings-base.php')
    ? __DIR__ . '/settings-base.php'
    : '/app/.devcontainer/conf/settings-base.php';
$settings = require $base;

$settings['interface']['language'] = 'de_DE';
$settings['interface']['enabled_languages'] = 'de_DE';
$settings['interface']['title'] = 'Poweradmin (MySQL + SQL, German)';
$settings['interface']['theme'] = 'modern';

return $settings;
