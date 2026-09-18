<?php

/**
 * Poweradmin devcontainer settings: MySQL + SQL backend (default)
 *
 * Everything comes from settings-base.php; this instance is the reference configuration.
 */

// The base sits next to this file in the repo; inside a container only the instance file is
// mounted, so fall back to the repo checkout mounted at /app.
$base = is_file(__DIR__ . '/settings-base.php')
    ? __DIR__ . '/settings-base.php'
    : '/app/.devcontainer/conf/settings-base.php';
return require $base;
