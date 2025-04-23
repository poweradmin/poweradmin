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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Repository\ZoneRepositoryInterface;

class ZoneService
{
    private ZoneRepositoryInterface $zoneRepository;

    public function __construct(ZoneRepositoryInterface $zoneRepository)
    {
        $this->zoneRepository = $zoneRepository;
    }

    public function getAvailableStartingLetters(int $userId, bool $viewOthers): array
    {
        return $this->zoneRepository->getDistinctStartingLetters($userId, $viewOthers);
    }

    public function checkDigitsAvailable(array $availableChars): bool
    {
        foreach ($availableChars as $char) {
            if (is_numeric($char)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get reverse zones with efficient database filtering and pagination
     *
     * @param string $permType Permission type ('all', 'own')
     * @param int $userId User ID
     * @param string $reverseType Type of reverse zones to fetch ('all', 'ipv4', 'ipv6')
     * @param int $offset Pagination offset
     * @param int $limit Maximum number of records to return
     * @param string $sortBy Column to sort by
     * @param string $sortDirection Sort direction ('ASC' or 'DESC')
     * @return array Array of reverse zones
     */
    public function getReverseZones(
        string $permType,
        int $userId,
        string $reverseType = 'all',
        int $offset = 0,
        int $limit = 25,
        string $sortBy = 'name',
        string $sortDirection = 'ASC'
    ): array {
        return $this->zoneRepository->getReverseZones(
            $permType,
            $userId,
            $reverseType,
            $offset,
            $limit,
            $sortBy,
            $sortDirection
        );
    }

    /**
     * Count reverse zones matching specific criteria
     *
     * @param string $permType Permission type ('all', 'own')
     * @param int $userId User ID
     * @param string $reverseType Type of reverse zones to count ('all', 'ipv4', 'ipv6')
     * @param string $sortBy Column to sort by
     * @param string $sortDirection Sort direction ('ASC' or 'DESC')
     * @return int Count of matching zones
     */
    public function countReverseZones(
        string $permType,
        int $userId,
        string $reverseType = 'all',
        string $sortBy = 'name',
        string $sortDirection = 'ASC'
    ): int {
        return $this->zoneRepository->getReverseZones(
            $permType,
            $userId,
            $reverseType,
            0,
            0,
            $sortBy,
            $sortDirection,
            true
        );
    }
}
