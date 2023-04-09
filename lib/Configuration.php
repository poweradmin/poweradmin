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

/**
 *  Configuration file functions
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class Configuration
{
    protected array $config = array();

    public function __construct()
    {
        $default_config = file_exists('inc/config-me.inc.php') ? $this->parseConfig('inc/config-me.inc.php') : [];
        $custom_config = array();
        if (file_exists('inc/config.inc.php')) {
            $custom_config = $this->parseConfig('inc/config.inc.php');
        }
        $this->config = array_merge($default_config, $custom_config);
    }

    private function parseConfig($fileName): array
    {
        $default_config_content = file_get_contents($fileName);
        $tokens = token_get_all($default_config_content);
        $last_token = null;
        $configItems = array();
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $token_type = $token[0];
                $token_value = $token[1];
                switch ($token_type) {
                    case T_VARIABLE:
                        $last_token = substr($token_value, 1);
                        break;
                    case T_STRING:
                    case T_CONSTANT_ENCAPSED_STRING:
                        if ($token_value == 'true' || $token_value == 'false') {
                            $configItems[$last_token] = $token_value == 'true';
                        } else {
                            $configItems[$last_token] = $token_value;
                        }
                        break;
                    case T_LNUMBER:
                        $configItems[$last_token] = intval($token_value);
                        break;
                    default:
                        break;
                }
            }
        }
        return $configItems;
    }

    public function get($name)
    {
        if (array_key_exists($name, $this->config)) {
            return $this->config[$name];
        } else {
            return null;
        }
    }

    public function getSanitized($name) {
        $raw_value = $this->get($name);
        return $raw_value ? str_replace(['"', "'"], "", $raw_value) : null;
    }
}