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

use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordLookupInterface;
use Poweradmin\Domain\Utility\DnsHelper;

/**
 * Finds the records a new zone would hide in its closest existing parent zone.
 * Delegation data is left out: NS and DS at the new apex, and the A/AAAA glue
 * for the name servers those NS records point at. Records under an existing
 * deeper zone are left out too, since that zone already hides them.
 */
class ShadowedRecordFinder
{
    public function __construct(
        private readonly DomainRepositoryInterface $zones,
        private readonly RecordLookupInterface $records
    ) {
    }

    /**
     * The closest existing zone above the given name, a served root zone last.
     * Only the closest one matters: anything further up is already hidden by it.
     *
     * @return array{id: int, name: string}|null
     */
    public function closestParent(string $zoneName): ?array
    {
        if ($zoneName === '.') {
            return null;
        }
        $ancestors = [...DnsHelper::ancestorNames($zoneName), '.'];

        $existing = [];
        foreach ($this->zones->findZoneIdsByNames($ancestors) as $name => $id) {
            $existing[$name === '.' ? '.' : DnsHelper::canonicalZoneName((string)$name)] = ['id' => $id, 'name' => (string)$name];
        }

        foreach ($ancestors as $name) {
            if (isset($existing[$name])) {
                return $existing[$name];
            }
        }

        return null;
    }

    /**
     * @param array{id: int, name: string} $parent From closestParent()
     */
    public function hiddenRecords(array $parent, string $zoneName): ?ShadowedRecords
    {
        $apex = DnsHelper::canonicalZoneName($zoneName);
        $rows = array_map(
            fn(array $r): array => [...$r, 'name' => DnsHelper::canonicalZoneName($r['name'])],
            $this->records->getRecordsAtOrUnder($parent['id'], $apex)
        );
        $deeperZones = array_map(
            fn(array $z): string => DnsHelper::canonicalZoneName($z['name']),
            $this->zones->findZonesUnder($apex)
        );
        // The parent's delegations of the topmost deeper zones move to the new zone, so they stay reported
        $deeperCuts = array_values(array_filter(
            $deeperZones,
            fn(string $z): bool => !$this->isUnderAny($z, array_values(array_diff($deeperZones, [$z])))
        ));

        $nsTargets = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'NS') {
                $nsTargets[$row['name']][DnsHelper::canonicalZoneName($row['content'])] = true;
            }
        }

        $hidden = [];
        foreach ($rows as $row) {
            if ($this->delegates($row, $apex, $nsTargets)) {
                continue;
            }
            $delegatesDeeperZone = array_filter($deeperCuts, fn(string $cut): bool => $this->delegates($row, $cut, $nsTargets)) !== [];
            if ($delegatesDeeperZone || !$this->isUnderAny($row['name'], $deeperZones)) {
                $hidden[] = ['name' => $row['name'], 'type' => $row['type']];
            }
        }

        return $hidden === [] ? null : new ShadowedRecords($parent['id'], $parent['name'], $hidden);
    }

    /**
     * Whether the row is delegation data for the cut: NS or DS at it, or A/AAAA
     * glue for a name server its NS records point at.
     *
     * @param array{name: string, type: string} $row
     * @param array<string, array<string, true>> $nsTargets NS targets by owner name
     */
    private function delegates(array $row, string $cut, array $nsTargets): bool
    {
        if ($row['name'] === $cut && in_array($row['type'], ['NS', 'DS'], true)) {
            return true;
        }

        return in_array($row['type'], ['A', 'AAAA'], true) && isset($nsTargets[$cut][$row['name']]);
    }

    /**
     * @param list<string> $zoneNames
     */
    private function isUnderAny(string $name, array $zoneNames): bool
    {
        foreach ($zoneNames as $zoneName) {
            if (DnsHelper::isWithinZone($name, $zoneName)) {
                return true;
            }
        }

        return false;
    }
}
