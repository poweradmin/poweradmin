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

namespace Poweradmin\Domain\Repository;

use Poweradmin\Domain\Model\ZoneGroup;

interface ZoneGroupRepositoryInterface
{
    /**
     * Find all groups that own a zone
     *
     * @param int $domainId
     * @return ZoneGroup[]
     */
    public function findByDomainId(int $domainId): array;

    /**
     * Find all zones owned by a group
     *
     * @param int $groupId
     * @return ZoneGroup[]
     */
    public function findByGroupId(int $groupId): array;

    /**
     * Add a group as zone owner
     *
     * @param int $domainId
     * @param int $groupId
     * @param int|null $zoneTemplId
     * @return ZoneGroup
     */
    public function add(int $domainId, int $groupId, ?int $zoneTemplId = null): ZoneGroup;

    /**
     * Remove a group from zone owners
     *
     * @param int $domainId
     * @param int $groupId
     * @return bool
     */
    public function remove(int $domainId, int $groupId): bool;

    /**
     * Check if a group owns a zone
     *
     * @param int $domainId
     * @param int $groupId
     * @return bool
     */
    public function exists(int $domainId, int $groupId): bool;
}
