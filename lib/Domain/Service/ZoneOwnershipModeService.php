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

namespace Poweradmin\Domain\Service;

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Resolves the dns.zone_ownership_mode setting into ownership-side flags.
 */
class ZoneOwnershipModeService
{
    public const MODE_BOTH = 'both';
    public const MODE_USERS_ONLY = 'users_only';
    public const MODE_GROUPS_ONLY = 'groups_only';

    private const VALID_MODES = [
        self::MODE_BOTH,
        self::MODE_USERS_ONLY,
        self::MODE_GROUPS_ONLY,
    ];

    private ConfigurationManager $config;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
    }

    public function getMode(): string
    {
        $mode = $this->config->get('dns', 'zone_ownership_mode', self::MODE_BOTH);

        if (!is_string($mode) || !in_array($mode, self::VALID_MODES, true)) {
            return self::MODE_BOTH;
        }

        return $mode;
    }

    public function isUserOwnerAllowed(): bool
    {
        return $this->getMode() !== self::MODE_GROUPS_ONLY;
    }

    public function isGroupOwnerAllowed(): bool
    {
        return $this->getMode() !== self::MODE_USERS_ONLY;
    }
}
