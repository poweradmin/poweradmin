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

namespace Poweradmin\Domain\Port;

/**
 * The search page's zone half: matching zones with their owners, paged and sorted,
 * and the unpaged match count. Each backend keeps its own row shape.
 */
interface ZoneSearchInterface
{
    /**
     * @param array $parameters query, zones, records, comments, wildcard, reverse
     * @param string $permissionView 'all', or 'own' to limit to zones $userId owns
     * @param int|null $userId The user an 'own' view is limited to; null matches nothing
     * @param bool $includeComments Add the zone comment to each row
     */
    public function searchZones(
        array $parameters,
        string $permissionView,
        ?int $userId,
        string $sortBy,
        string $sortDirection,
        int $rowAmount,
        bool $includeComments,
        int $page
    ): array;

    public function getTotalZones(array $parameters, string $permissionView, ?int $userId): int;
}
