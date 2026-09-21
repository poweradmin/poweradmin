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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Port\ZoneSearchInterface;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Infrastructure\Utility\ResultPaginator;

/**
 * Zone search over the PowerDNS API, enriched with owners and comments from
 * Poweradmin's zones table and filtered, sorted and paged in memory.
 */
class ApiZoneSearch extends ApiSearchBase implements ZoneSearchInterface
{
    public function searchZones(
        array $parameters,
        string $permissionView,
        ?int $userId,
        string $sortBy,
        string $sortDirection,
        int $rowAmount,
        bool $includeComments,
        int $page
    ): array {
        return $this->search($parameters, $permissionView, $userId, $sortBy, $sortDirection, $rowAmount, $includeComments, $page, true);
    }

    /**
     * Counts every match unpaged, so record counts stay off: they would cost
     * an API call per matched zone only to be discarded.
     */
    public function getTotalZones(array $parameters, string $permissionView, ?int $userId): int
    {
        return count($this->search($parameters, $permissionView, $userId, 'name', 'ASC', PHP_INT_MAX, false, 1, false));
    }

    private function search(
        array $parameters,
        string $permissionView,
        ?int $userId,
        string $sortBy,
        string $sortDirection,
        int $rowAmount,
        bool $includeComments,
        int $page,
        bool $includeRecordCount
    ): array {
        $query = $parameters['query'] ?? '';
        if (empty($query) || !$parameters['zones']) {
            return [];
        }

        $parameters = $this->preprocessSearchQuery($parameters);
        $query = $parameters['query'];

        $results = $this->backendProvider->searchDnsData($query, 'zone', 10000);
        $zones = $results['zones'];

        // A reverse query is a second search whose new names are appended
        $reverseQuery = $parameters['reverse_query'] ?? '';
        if (!empty($reverseQuery)) {
            $reverseResults = $this->backendProvider->searchDnsData($reverseQuery, 'zone', 10000);
            $reverseZones = $reverseResults['zones'];
            if (!empty($reverseZones)) {
                $seenNames = array_flip(array_column($zones, 'name'));
                foreach ($reverseZones as $rz) {
                    if (!isset($seenNames[$rz['name'] ?? ''])) {
                        $zones[] = $rz;
                    }
                }
            }
        }

        if (empty($zones)) {
            return [];
        }

        // Wildcard off means an exact match on the query or its reverse form
        if (isset($parameters['wildcard']) && !$parameters['wildcard']) {
            $zones = array_values(array_filter($zones, function ($zone) use ($query, $reverseQuery) {
                $name = $zone['name'] ?? '';
                return strcasecmp($name, $query) === 0
                    || (!empty($reverseQuery) && strcasecmp($name, $reverseQuery) === 0);
            }));
            if (empty($zones)) {
                return [];
            }
        }

        $zones = $this->enrichZonesWithOwnership($zones);

        if ($permissionView === 'own') {
            if ($userId) {
                $zones = $this->filterZonesByOwnership($zones, $userId);
            } else {
                return [];
            }
        }

        $apiSortBy = $sortBy;
        if ($sortBy === 'fullname') {
            $apiSortBy = 'owner_username';
        }

        $zones = ResultPaginator::sort($zones, $apiSortBy, $sortDirection);

        $offset = ($page - 1) * $rowAmount;
        $zones = ResultPaginator::paginate($zones, $offset, $rowAmount);

        // After paging so the per-zone API calls scale with the page, not the
        // whole result set
        if ($includeRecordCount) {
            $zones = $this->enrichWithRecordCounts($zones);
        }

        $result = [];
        foreach ($zones as $zone) {
            $formatted = [
                'id' => $zone['id'] ?? 0,
                'name' => DnsIdnService::toUtf8($zone['name'] ?? ''),
                'type' => $zone['type'] ?? '',
                'count_records' => $zone['count_records'] ?? 0,
                'user_id' => $zone['owner_ids'][0] ?? 0,
                'fullname' => $this->formatOwnerFullnames($zone),
                'owner_fullnames' => $zone['full_names'] ?? [],
                'owner_usernames' => $zone['owners'] ?? [],
            ];

            if ($includeComments) {
                $formatted['comment'] = $zone['comment'] ?? '';
            }

            $result[] = $formatted;
        }

        return $result;
    }

    /**
     * Keep only the zones the user owns directly or through a group.
     */
    private function filterZonesByOwnership(array $zones, int $userId): array
    {
        $ownedDomainIds = $this->ownedZoneIds($userId);

        return array_values(array_filter($zones, function ($zone) use ($ownedDomainIds) {
            $id = $zone['id'] ?? 0;
            return in_array($id, $ownedDomainIds, true);
        }));
    }

    /**
     * Attach owners and the zone comment from Poweradmin's zones table.
     */
    private function enrichZonesWithOwnership(array $zones): array
    {
        if (empty($zones)) {
            return $zones;
        }

        $stmt = $this->db->query(
            "SELECT " . $this->canonicalZoneId('z') . " AS domain_id, z.owner, z.comment, u.username, u.fullname
             FROM zones z
             LEFT JOIN users u ON z.owner = u.id"
        );

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $domainId = (int)$row['domain_id'];
            foreach ($zones as $i => &$zone) {
                if (($zone['id'] ?? 0) === $domainId) {
                    if (!isset($zone['owners'])) {
                        $zone['owners'] = [];
                        $zone['full_names'] = [];
                        $zone['owner_ids'] = [];
                        $zone['comment'] = $row['comment'] ?? '';
                    }
                    if ($row['username'] !== null) {
                        $zone['owners'][] = $row['username'];
                        $zone['full_names'][] = $row['fullname'] ?: '';
                        $zone['owner_ids'][] = (int)$row['owner'];
                    }
                }
            }
            unset($zone);
        }

        // owner_username is what a 'fullname' sort orders by
        foreach ($zones as &$zone) {
            if (!isset($zone['owners'])) {
                $zone['owners'] = [];
                $zone['full_names'] = [];
                $zone['owner_ids'] = [];
            }
            $zone['owner_username'] = $zone['owners'][0] ?? '';
        }
        unset($zone);

        return $zones;
    }

    /**
     * Fill in record counts, one API call per zone: PowerDNS's zone list carries
     * no record count, so only ever pass the zones on the current page.
     */
    private function enrichWithRecordCounts(array $zones): array
    {
        $recordCounts = [];
        foreach ($zones as $zone) {
            $id = (int)($zone['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $recordCounts[$id] = $this->backendProvider->countZoneRecords($id);
        }

        foreach ($zones as &$zone) {
            $zone['count_records'] = $recordCounts[$zone['id'] ?? 0] ?? 0;
        }
        unset($zone);

        return $zones;
    }

    /**
     * Owner display in the same "Full Name (username)" form as ZoneSearch.
     */
    private function formatOwnerFullnames(array $zone): string
    {
        $owners = $zone['owners'] ?? [];
        $fullNames = $zone['full_names'] ?? [];
        $parts = [];
        foreach ($owners as $i => $username) {
            $fullname = $fullNames[$i] ?? '';
            $parts[] = $fullname ? "$fullname ($username)" : $username;
        }
        return implode(', ', $parts);
    }
}
