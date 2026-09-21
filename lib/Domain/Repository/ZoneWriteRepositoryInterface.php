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
 * Zone row, comment and metadata writes (plus the metadata read the editor pairs with them); one of the three roles ZoneRepositoryInterface combines.
 */
interface ZoneWriteRepositoryInterface
{
    /**
     * Update zone comment
     *
     * @param int $zoneId The zone ID
     * @param string $comment The new comment
     * @return bool True if updated successfully
     */
    public function updateZoneComment(int $zoneId, string $comment): bool;

    /**
     * Update zone metadata
     *
     * @param int $zoneId The zone ID
     * @param array $updates Array of field => value pairs to update
     * @return bool True if zone was updated successfully
     */
    public function updateZone(int $zoneId, array $updates): bool;

    /**
     * Delete a zone by ID
     *
     * @param int $zoneId The zone ID
     * @return bool True if zone was deleted successfully
     */
    public function deleteZone(int $zoneId): bool;
}
