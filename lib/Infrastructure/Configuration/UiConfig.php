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

use Poweradmin\Domain\Config\UiConfigDefaults;

class UiConfig implements ConfigurationInterface
{
    private array $config;
    private ConfigurationManager $configManager;

    public function __construct()
    {
        // Get default settings
        $this->config = UiConfigDefaults::getDefaults();

        // Load settings from the central configuration manager
        $this->configManager = ConfigurationManager::getInstance();
        $this->configManager->initialize();
    }

    public function get(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_merge($this->config, $this->configManager->getGroup('interface'));
        }

        // Map old config keys to new interface settings keys
        $keyMapping = [
            'show_record_id_column' => 'show_record_id',
            'position_record_form_top' => 'position_record_form_top',
            'position_save_button_top' => 'position_save_button_top',
        ];

        $lookupKey = $keyMapping[$key] ?? $key;

        // Check if the setting exists in the interface group
        $interfaceSettings = $this->configManager->getGroup('interface');
        if (isset($interfaceSettings[$lookupKey])) {
            return $interfaceSettings[$lookupKey];
        }

        // Fall back to default configurations
        return $this->config[$key] ?? $default;
    }
}
