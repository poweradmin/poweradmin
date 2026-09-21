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

namespace Poweradmin\Domain\Repository;

/**
 * Read access to zone SOA serials.
 */
interface ZoneSerialReadInterface
{
    /**
     * Get Serial for Zone ID
     *
     * @param int $zid Zone ID
     *
     * @return string Serial Number or false if not found
     */
    public function getSerialByZid(int $zid): string;

    /**
     * Get serial numbers for multiple zones in a single query
     *
     * @param array $zoneIds Array of zone IDs
     * @return array<int, string> Array mapping zone ID to serial number
     */
    public function getSerialsByZoneIds(array $zoneIds): array;
}
