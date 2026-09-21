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

use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;

/**
 * Maps each reverse zone to the forward zones its PTR records point at, with a PTR count per forward zone.
 */
class ForwardZoneAssociationService
{
    private ZoneReadRepositoryInterface $zoneRepository;

    public function __construct(ZoneReadRepositoryInterface $zoneRepository)
    {
        $this->zoneRepository = $zoneRepository;
    }

    /**
     * Get associated forward zones for reverse zones by analyzing PTR records
     *
     * @param array $reverseZones Array of reverse zone data
     * @return array Associative array mapping reverse zone IDs to arrays of forward zone info
     */
    public function getAssociatedForwardZones(array $reverseZones): array
    {
        if (empty($reverseZones)) {
            return [];
        }

        $reverseZoneIds = array_column($reverseZones, 'id');
        $associated = array_fill_keys($reverseZoneIds, []);
        $seenPtrs = [];

        // One PTR content may match several forward zones; count each PTR once per reverse zone.
        foreach ($this->zoneRepository->findForwardZonesByPtrRecords($reverseZoneIds) as $match) {
            $reverseId = $match['reverse_domain_id'];
            $forwardId = $match['forward_domain_id'];
            $ptrKey = $reverseId . '-' . $match['ptr_content'];
            if (isset($seenPtrs[$ptrKey])) {
                continue;
            }
            $seenPtrs[$ptrKey] = true;

            $associated[$reverseId][$forwardId] ??= ['id' => $forwardId, 'name' => $match['forward_domain_name'], 'ptr_records' => 0];
            $associated[$reverseId][$forwardId]['ptr_records']++;
        }

        return array_map('array_values', $associated);
    }
}
