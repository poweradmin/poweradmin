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

namespace Poweradmin\Domain\Service\Zone;

use Poweradmin\Domain\Service\Auth\ZoneAccessPolicy;

/**
 * Who owns which of a page of zones, resolved once so per-row controls do not
 * query per zone. Ownership is direct (zones.owner) or through any group the
 * user belongs to.
 */
final readonly class ZoneOwnershipIndex
{
    /**
     * @param int $userId The user the index answers for
     * @param list<int> $userGroupIds Groups the user belongs to
     * @param array<int, list<int>> $ownersByZone Zone id => direct owner user ids
     * @param array<int, list<int>> $groupsByZone Zone id => owning group ids
     */
    public function __construct(
        private int $userId,
        private array $userGroupIds,
        private array $ownersByZone,
        private array $groupsByZone
    ) {
    }

    public function owns(int $zoneId): bool
    {
        return in_array($this->userId, $this->ownersByZone[$zoneId] ?? [], true)
            || array_intersect($this->userGroupIds, $this->groupsByZone[$zoneId] ?? []) !== [];
    }

    /** Whether any zone on the page is group-owned, so group names are worth loading. */
    public function hasGroupOwners(): bool
    {
        return $this->groupsByZone !== [];
    }

    /** @return list<int> */
    public function groupIds(int $zoneId): array
    {
        return $this->groupsByZone[$zoneId] ?? [];
    }

    /**
     * Whether a permission level ("all", "own", "own_as_client", "none") lets the
     * user act on the zone; the "_own" levels additionally need ownership.
     */
    public function allows(string $level, int $zoneId): bool
    {
        return ZoneAccessPolicy::canEditZone($level, $this->owns($zoneId));
    }
}
