<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace PoweradminInstall;

/**
 * Centralized system requirements configuration for Poweradmin installer
 */
class SystemRequirements
{
    /**
     * Minimum PHP version required for Poweradmin
     */
    public const MIN_PHP_VERSION = '8.1.0';

    /**
     * Required PHP extensions
     */
    public const REQUIRED_EXTENSIONS = [
        'intl',
        'gettext',
        'openssl',
        'filter',
        'tokenizer',
        'pdo',
        'xml',
    ];

    /**
     * Database extensions (at least one must be installed)
     */
    public const DATABASE_EXTENSIONS = [
        'pdo-mysql',
        'pdo-pgsql',
        'pdo-sqlite',
    ];

    /**
     * Optional extensions
     */
    public const OPTIONAL_EXTENSIONS = [
        'ldap',
    ];

    /**
     * Get required extensions with their loaded status
     *
     * @return array<string, bool>
     */
    public static function getRequiredExtensionsStatus(): array
    {
        $extensions = [];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }
        return $extensions;
    }

    /**
     * Get database extensions with their loaded status
     *
     * @return array<string, bool>
     */
    public static function getDatabaseExtensionsStatus(): array
    {
        $extensions = [];
        foreach (self::DATABASE_EXTENSIONS as $extension) {
            $extensionName = self::getExtensionName($extension);
            $extensions[$extension] = extension_loaded($extensionName);
        }
        return $extensions;
    }

    /**
     * Get optional extensions with their loaded status
     *
     * @return array<string, bool>
     */
    public static function getOptionalExtensionsStatus(): array
    {
        $extensions = [];
        foreach (self::OPTIONAL_EXTENSIONS as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }
        return $extensions;
    }

    /**
     * Check if PHP version meets minimum requirements
     *
     * @return bool
     */
    public static function isPhpVersionSupported(): bool
    {
        return version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');
    }

    /**
     * Check if all required extensions are loaded
     *
     * @return bool
     */
    public static function areRequiredExtensionsLoaded(): bool
    {
        return !in_array(false, self::getRequiredExtensionsStatus(), true);
    }

    /**
     * Check if at least one database extension is loaded
     *
     * @return bool
     */
    public static function isDatabaseExtensionLoaded(): bool
    {
        return in_array(true, self::getDatabaseExtensionsStatus(), true);
    }

    /**
     * Check if all system requirements are met
     *
     * @return bool
     */
    public static function areAllRequirementsMet(): bool
    {
        return self::isPhpVersionSupported() &&
               self::areRequiredExtensionsLoaded() &&
               self::isDatabaseExtensionLoaded();
    }

    /**
     * Get the actual extension name from the friendly name
     *
     * @param string $extension The extension friendly name
     * @return string The actual extension name
     */
    private static function getExtensionName(string $extension): string
    {
        // Map friendly names to actual extension names
        $extensionMap = [
            'pdo-mysql' => 'pdo_mysql',
            'pdo-pgsql' => 'pdo_pgsql',
            'pdo-sqlite' => 'pdo_sqlite',
        ];

        return $extensionMap[$extension] ?? $extension;
    }
}
