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

namespace Poweradmin\Domain\Enum;

use Poweradmin\Domain\Config\ConfigurationInterface;

/**
 * Where zone data lives: the PowerDNS tables on the Poweradmin database, or a
 * PowerDNS server reached over its HTTP API. Read from dns.backend before any
 * backend provider exists, so modules can shape their routes to it.
 */
enum DnsBackendKind: string
{
    case SQL = 'sql';
    case API = 'api';

    /**
     * Anything other than "api" is the SQL backend, as the provider factory has always read it.
     */
    public static function fromConfig(ConfigurationInterface $config): self
    {
        return $config->get('dns', 'backend') === 'api' ? self::API : self::SQL;
    }

    public function isApi(): bool
    {
        return $this === self::API;
    }
}
