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

namespace Poweradmin;

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Class AppConfiguration
 *
 * This class handles the loading and parsing of configuration files for the Poweradmin application.
 *
 * @package Poweradmin
 */
class AppConfiguration implements ConfigurationInterface
{
    /**
     * @var array The configuration settings.
     */
    protected array $config;

    /**
     * AppConfiguration constructor.
     *
     * @param string $defaultConfigFile Path to the default configuration file.
     * @param string $customConfigFile Path to the custom configuration file.
     */
    public function __construct(
        string $defaultConfigFile = 'inc/config-defaults.inc.php',
        string $customConfigFile = 'inc/config.inc.php'
    ) {
        $defaultConfig = $this->loadAndParseConfig($defaultConfigFile);
        $customConfig = $this->loadAndParseConfig($customConfigFile);
        $this->config = array_merge($defaultConfig, $customConfig);
    }

    /**
     * Loads and parses a configuration file.
     *
     * @param string $fileName Path to the configuration file.
     * @return array The parsed configuration settings.
     */
    private function loadAndParseConfig(string $fileName): array
    {
        if (!file_exists($fileName)) {
            return [];
        }

        if (!function_exists('token_get_all')) {
            die("You have to install the PHP tokenizer extension!");
        }

        $configContent = file_get_contents($fileName);
        $tokens = token_get_all($configContent);
        $lastToken = null;
        $configItems = [];

        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$tokenType, $tokenValue] = $token;

                switch ($tokenType) {
                    case T_VARIABLE:
                        $lastToken = substr($tokenValue, 1);
                        break;
                    case T_STRING:
                    case T_CONSTANT_ENCAPSED_STRING:
                    case T_LNUMBER:
                        if ($lastToken !== null) {
                            $configItems[$lastToken] = $this->parseTokenValue($tokenValue);
                            $lastToken = null;
                        }
                        break;
                    default:
                        break;
                }
            }
        }

        return $configItems;
    }

    /**
     * Parses a token value.
     *
     * @param string $tokenValue The token value to parse.
     * @return mixed The parsed value.
     */
    public function parseTokenValue(string $tokenValue): mixed
    {
        if (strtolower($tokenValue) === 'true') {
            return true;
        }

        if (strtolower($tokenValue) === 'false') {
            return false;
        }

        if (is_numeric($tokenValue)) {
            return $tokenValue + 0; // Convert to int or float
        }

        if (defined($tokenValue)) {
            return constant($tokenValue);
        }

        return trim($tokenValue, "'\"");
    }

    /**
     * Gets a configuration value.
     *
     * @param string $name The name of the configuration setting.
     * @param mixed $default The default value to return if the setting is not found.
     * @return mixed The configuration value.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        if (array_key_exists($name, $this->config)) {
            $value = $this->config[$name];
            if (is_bool($value)) {
                return $value;
            }
            return str_replace(['"', "'"], "", $value);
        } else {
            return $default;
        }
    }

    /**
     * Gets all configuration values.
     *
     * @return array All configuration settings.
     */
    public function getAll(): array
    {
        $items = $this->config;
        foreach ($items as $key => $value) {
            $items[$key] = $this->get($key);
        }
        return $items;
    }

    /**
     * Checks if login token validation is enabled.
     *
     * @return bool True if login token validation is enabled, false otherwise.
     */
    public function isLoginTokenValidationEnabled(): bool
    {
        return $this->get('login_token_validation', true);
    }

    /**
     * Checks if global token validation is enabled.
     *
     * @return bool True if global token validation is enabled, false otherwise.
     */
    public function isGlobalTokenValidationEnabled(): bool
    {
        return $this->get('global_token_validation', true);
    }
}
