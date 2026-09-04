<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Infrastructure\Configuration;

/**
 * Resolves the configured theme base path for on-disk checks.
 *
 * `interface.theme_base_path` doubles as a web/URL path in templates, so it must
 * stay relative there. But `is_dir()`/`file_exists()` on a relative path depend on
 * the process working directory, which is not the app root under CLI/cron/some
 * SAPIs - producing spurious "theme missing" errors. Every filesystem check should
 * route the base path through this resolver so they agree on an app-rooted path.
 */
class ThemePathResolver
{
    /**
     * @param mixed $themeBasePath The configured base path (falls back to 'templates' if unusable)
     * @return string An absolute filesystem path, rooted at the app directory for relative inputs
     */
    public static function toFilesystemPath(mixed $themeBasePath): string
    {
        if (!is_string($themeBasePath) || $themeBasePath === '') {
            $themeBasePath = 'templates';
        }

        if (str_starts_with($themeBasePath, '/')) {
            return $themeBasePath;
        }

        // __DIR__ = lib/Infrastructure/Configuration; three levels up is the app root.
        return dirname(__DIR__, 3) . '/' . $themeBasePath;
    }
}
