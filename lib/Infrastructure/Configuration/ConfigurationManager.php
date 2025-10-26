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

namespace Poweradmin\Infrastructure\Configuration;

/**
 * Class ConfigurationManager
 *
 * This class is responsible for loading and accessing configuration values.
 * It combines legacy configuration, defaults, and the new settings structure.
 */
class ConfigurationManager implements ConfigurationInterface
{
    private static ?ConfigurationManager $instance = null;
    private array $settings = [];
    private bool $initialized = false;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
    }

    /**
     * Get the singleton instance
     *
     * @return ConfigurationManager
     */
    public static function getInstance(): ConfigurationManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize the configuration
     *
     * @return void
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Initialize with empty settings
        $this->settings = [
            'database' => [],
            'security' => [],
            'interface' => [],
            'dns' => [],
            'mail' => [],
            'dnssec' => [],
            'pdns_api' => [],
            'logging' => [],
            'ldap' => [],
            'misc' => [],
        ];

        // Load default values first
        $defaultConfigFile = __DIR__ . '/../../../config/settings.defaults.php';
        if (file_exists($defaultConfigFile)) {
            $defaultSettings = require $defaultConfigFile;
            if (is_array($defaultSettings)) {
                $this->settings = $this->mergeConfig($this->settings, $defaultSettings);
            }
        }

        // Try to load user configuration file
        $newConfigFile = __DIR__ . '/../../../config/settings.php';
        $newConfigExists = false;

        if (file_exists($newConfigFile)) {
            $newSettings = require $newConfigFile;
            if (is_array($newSettings)) {
                $this->settings = $this->mergeConfig($this->settings, $newSettings);
                $newConfigExists = true;
            }
        }

        // Validate configuration and log errors (non-blocking)
        if ($newConfigExists) {
            $this->validateConfiguration();
        }

        $this->initialized = true;
    }



    /**
     * Get a configuration value by its key
     *
     * @param string $group Configuration group
     * @param string $key Configuration key, can use dot notation for nested values (e.g. 'account_lockout.enable_lockout')
     * @param mixed $default Default value to return if the key doesn't exist (default: null)
     * @return mixed Configuration value
     */
    public function get(string $group, string $key, mixed $default = null): mixed
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        // Handle nested keys with dot notation
        if (str_contains($key, '.')) {
            $path = explode('.', $key);
            $value = $this->settings[$group] ?? null;

            foreach ($path as $pathPart) {
                if (!isset($value[$pathPart])) {
                    return $default;
                }
                $value = $value[$pathPart];
            }

            return $value;
        }

        // Handle direct key
        if (isset($this->settings[$group][$key])) {
            return $this->settings[$group][$key];
        }

        return $default;
    }

    /**
     * Get an entire configuration group
     *
     * @param string $group Configuration group
     * @return array Configuration group values
     */
    public function getGroup(string $group): array
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return $this->settings[$group] ?? [];
    }

    /**
     * Get all configuration settings
     *
     * @return array All settings
     */
    public function getAll(): array
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return $this->settings;
    }

    /**
     * Validate configuration and log errors
     *
     * This helps detect common configuration issues like:
     * - Missing protocol prefix in API URLs
     * - Invalid data types
     *
     * @return void
     */
    /**
     * Merge configuration arrays properly handling indexed arrays
     *
     * Unlike array_replace_recursive which merges indexed arrays,
     * this method replaces them completely when they exist in the new config.
     *
     * @param array $base Base configuration
     * @param array $new New configuration to merge
     * @return array Merged configuration
     */
    private function mergeConfig(array $base, array $new): array
    {
        foreach ($new as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                // Check if this is an indexed array (not associative)
                if (array_is_list($value)) {
                    // It's an indexed array - replace it completely
                    $base[$key] = $value;
                } else {
                    // It's an associative array - merge recursively
                    $base[$key] = $this->mergeConfig($base[$key], $value);
                }
            } else {
                // Not an array or doesn't exist in base - just set it
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function validateConfiguration(): void
    {
        $validator = new ConfigValidator($this->settings);
        if (!$validator->validate()) {
            $errors = $validator->getErrors();
            foreach ($errors as $key => $error) {
                error_log(sprintf(
                    "Configuration validation error [%s]: %s",
                    $key,
                    $error
                ));
            }
        }
    }
}
