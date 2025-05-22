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

/**
 * Service for managing forward zone associations with reverse zones
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Repository\ZoneRepositoryInterface;

class ForwardZoneAssociationService
{
    private ZoneRepositoryInterface $zoneRepository;

    public function __construct(ZoneRepositoryInterface $zoneRepository)
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

        $reverseZoneIds = $this->extractZoneIds($reverseZones);
        $ptrMatches = $this->zoneRepository->findForwardZonesByPtrRecords($reverseZoneIds);

        return $this->buildAssociationMap($reverseZoneIds, $ptrMatches);
    }

    /**
     * Extract zone IDs from reverse zones array
     *
     * @param array $reverseZones
     * @return array
     */
    private function extractZoneIds(array $reverseZones): array
    {
        return array_map(function ($zone) {
            return $zone['id'];
        }, $reverseZones);
    }

    /**
     * Build the final association map from PTR record matches
     *
     * @param array $reverseZoneIds
     * @param array $ptrMatches
     * @return array
     */
    private function buildAssociationMap(array $reverseZoneIds, array $ptrMatches): array
    {
        $associatedZones = $this->initializeAssociationMap($reverseZoneIds);
        $processedPtrs = [];

        foreach ($ptrMatches as $match) {
            $reverseDomainId = $match['reverse_domain_id'];
            $forwardDomainId = $match['forward_domain_id'];
            $forwardDomainName = $match['forward_domain_name'];
            $ptrContent = $match['ptr_content'];

            $ptrKey = $reverseDomainId . '-' . $ptrContent;

            if (isset($processedPtrs[$ptrKey])) {
                continue;
            }

            $processedPtrs[$ptrKey] = true;

            $this->addOrUpdateForwardZoneEntry(
                $associatedZones,
                $reverseDomainId,
                $forwardDomainId,
                $forwardDomainName
            );
        }

        return $this->convertToIndexedArrays($associatedZones, $reverseZoneIds);
    }

    /**
     * Initialize association map with empty arrays for each reverse zone
     *
     * @param array $reverseZoneIds
     * @return array
     */
    private function initializeAssociationMap(array $reverseZoneIds): array
    {
        $associatedZones = [];
        foreach ($reverseZoneIds as $zoneId) {
            $associatedZones[$zoneId] = [];
        }
        return $associatedZones;
    }

    /**
     * Add or update forward zone entry in the association map
     *
     * @param array &$associatedZones
     * @param int $reverseDomainId
     * @param int $forwardDomainId
     * @param string $forwardDomainName
     */
    private function addOrUpdateForwardZoneEntry(
        array &$associatedZones,
        int $reverseDomainId,
        int $forwardDomainId,
        string $forwardDomainName
    ): void {
        if (!isset($associatedZones[$reverseDomainId][$forwardDomainId])) {
            $associatedZones[$reverseDomainId][$forwardDomainId] = [
                'id' => $forwardDomainId,
                'name' => $forwardDomainName,
                'ptr_records' => 1
            ];
        } else {
            $associatedZones[$reverseDomainId][$forwardDomainId]['ptr_records']++;
        }
    }

    /**
     * Convert associative arrays to indexed arrays for consistent output format
     *
     * @param array $associatedZones
     * @param array $reverseZoneIds
     * @return array
     */
    private function convertToIndexedArrays(array $associatedZones, array $reverseZoneIds): array
    {
        foreach ($reverseZoneIds as $zoneId) {
            if (isset($associatedZones[$zoneId]) && is_array($associatedZones[$zoneId])) {
                $associatedZones[$zoneId] = array_values($associatedZones[$zoneId]);
            } else {
                $associatedZones[$zoneId] = [];
            }
        }

        return $associatedZones;
    }
}
