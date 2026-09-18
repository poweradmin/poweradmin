<?php

/**
 * Poweradmin devcontainer settings: MySQL + PowerDNS API backend (experimental)
 *
 * Everything not set here comes from settings-base.php (mounted next to this file).
 */

// The base sits next to this file in the repo; inside a container only the instance file is
// mounted, so fall back to the repo checkout mounted at /app.
$base = is_file(__DIR__ . '/settings-base.php')
    ? __DIR__ . '/settings-base.php'
    : '/app/.devcontainer/conf/settings-base.php';
$settings = require $base;

$settings['interface']['title'] = 'Poweradmin (MySQL + API)';
$settings['interface']['display_signed_serial_in_zone_list'] = true;
$settings['dns']['backend'] = 'api';
$settings['modules']['secondary_zone_import'] = [
  'enabled' => true,
];

return $settings;
