<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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

class LegacyConfiguration implements ConfigurationInterface
{
    protected array $config;

    public function __construct(
        string $defaultConfigFile = 'inc/config-defaults.inc.php',
        string $customConfigFile = 'inc/config.inc.php'
    )
    {
        $defaultConfig = $this->loadAndParseConfig($defaultConfigFile);
        $customConfig = $this->loadAndParseConfig($customConfigFile);
        $this->config = array_merge($defaultConfig, $customConfig);
    }

    private function loadAndParseConfig(string $fileName): array
    {
        if (!file_exists($fileName)) {
            return [];
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
                        $configItems[$lastToken] = $this->parseTokenValue($tokenValue);
                        break;
                    case T_LNUMBER:
                        $configItems[$lastToken] = intval($tokenValue);
                        break;
                    default:
                        break;
                }
            }
        }

        return $configItems;
    }

    private function parseTokenValue(string $tokenValue): mixed
    {
        if (strtolower($tokenValue) === 'true') {
            return true;
        }

        if (strtolower($tokenValue) === 'false') {
            return false;
        }

        if (defined($tokenValue)) {
            return constant($tokenValue);
        }

        return $tokenValue;
    }

    public function get($name): mixed
    {
        if (array_key_exists($name, $this->config)) {
            $value = $this->config[$name];
            if (is_bool($value)) {
                return $value;
            }
            return str_replace(['"', "'"], "", $value);
        } else {
            return null;
        }
    }
}
