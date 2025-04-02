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

use Poweradmin\Domain\Config\MailConfigDefaults;

class MailConfig implements ConfigurationInterface
{
    private array $config;
    private ConfigurationManager $configManager;

    public function __construct()
    {
        // Get default settings
        $this->config = MailConfigDefaults::getDefaults();

        // Load settings from the central configuration manager
        $this->configManager = ConfigurationManager::getInstance();
        $this->configManager->initialize();
    }

    public function get(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_merge($this->config, $this->configManager->getGroup('mail'));
        }

        // Check if the setting exists in the mail group
        $mailSettings = $this->configManager->getGroup('mail');
        if (isset($mailSettings[$key])) {
            return $mailSettings[$key];
        }

        // Fall back to default configurations
        return $this->config[$key] ?? $default;
    }

    public function isEnabled(): bool
    {
        return (bool)$this->get('enabled', false);
    }
}
