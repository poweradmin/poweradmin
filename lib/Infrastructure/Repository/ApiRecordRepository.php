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

use Poweradmin\Application\Service\ResultPaginator;
use Poweradmin\Domain\Model\Constants;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;

/**
 * API-backend record repository.
 * Fetches DNS data via PowerDNS REST API, comments from API RRset data.
 */
class ApiRecordRepository implements RecordRepositoryInterface
{
    private DnsBackendProvider $backendProvider;

    public function __construct(DnsBackendProvider $backendProvider)
    {
        $this->backendProvider = $backendProvider;
    }

    /**
     * Enrich records with comments from API RRset data.
     * The API is the sole source of truth for comments in API mode.
     */
    private function enrichRecordsWithComments(array &$records): void
    {
        foreach ($records as &$record) {
            $record['comment'] = $record['api_comment'] ?? null;
            unset($record['api_comment']);
        }
        unset($record);
    }

    public function getZoneIdFromRecordId(int|string $rid): int
    {
        return $this->backendProvider->getZoneIdFromRecordId($rid);
    }

    public function countZoneRecords(int $zone_id): int
    {
        return $this->backendProvider->countZoneRecords($zone_id);
    }

    public function getRecordDetailsFromRecordId(int|string $rid): array
    {
        $record = $this->backendProvider->getRecordById($rid);
        if ($record === null) {
            return [];
        }
        return [
            'rid' => $record['id'],
            'zid' => $record['domain_id'],
            'name' => $record['name'],
            'type' => $record['type'],
            'content' => $record['content'],
            'ttl' => $record['ttl'],
            'prio' => $record['prio'],
            'disabled' => $record['disabled'] ?? 0,
        ];
    }

    public function getRecordFromId(int|string $id): ?array
    {
        $record = $this->backendProvider->getRecordById($id);
        if ($record === null) {
            return null;
        }
        $record['ordername'] = $record['ordername'] ?? '';
        $record['auth'] = $record['auth'] ?? 1;
        return $record;
    }

    public function getRecordsFromDomainId(string $db_type, int $id, int $rowstart = 0, int $rowamount = Constants::DEFAULT_MAX_ROWS, string $sortby = 'name', string $sortDirection = 'ASC', bool $fetchComments = false): array
    {
        $zoneName = $this->backendProvider->getZoneNameById($id);
        if ($zoneName === null) {
            return [];
        }

        $records = $this->backendProvider->getZoneRecords($id, $zoneName);

        // Sort
        $records = ResultPaginator::sort($records, $sortby, $sortDirection);

        // Paginate
        if ($rowamount < Constants::DEFAULT_MAX_ROWS) {
            $records = ResultPaginator::paginate($records, $rowstart, $rowamount);
        }

        // Enrich with comments if requested
        if ($fetchComments && !empty($records)) {
            $this->enrichRecordsWithComments($records);
        }

        return $records;
    }

    public function recidToDomid(int|string $id): int
    {
        return $this->backendProvider->getZoneIdFromRecordId($id);
    }

    public function recordNameExists(string $name): bool
    {
        $result = $this->backendProvider->searchDnsData($name, 'record', 1);
        foreach ($result['records'] as $r) {
            if ($r['name'] === $name) {
                return true;
            }
        }
        return false;
    }

    public function hasNonDelegationRecords(string $name): bool
    {
        $result = $this->backendProvider->searchDnsData($name, 'record', 100);
        foreach ($result['records'] as $r) {
            if ($r['name'] === $name && !in_array($r['type'], ['NS', 'DS'], true)) {
                return true;
            }
        }
        return false;
    }

    public function hasSimilarRecords(int $domain_id, string $name, string $type, int|string $record_id): bool
    {
        $records = $this->backendProvider->getRecordsByZoneId($domain_id, $type);
        foreach ($records as $r) {
            if ($r['name'] === $name && ($r['id'] ?? 0) != $record_id) {
                return true;
            }
        }
        return false;
    }

    public function recordExists(int $domain_id, string $name, string $type, string $content): bool
    {
        return $this->backendProvider->recordExists($domain_id, $name, $type, $content);
    }

    public function getRecordId(int $domain_id, string $name, string $type, string $content, ?int $prio = null, ?int $ttl = null): int|string|null
    {
        $records = $this->backendProvider->getRecordsByZoneId($domain_id, $type);
        foreach ($records as $r) {
            if ($r['name'] === $name && $r['type'] === $type && $r['content'] === $content) {
                if ($prio !== null && ($r['prio'] ?? 0) != $prio) {
                    continue;
                }
                if ($ttl !== null && ($r['ttl'] ?? 0) != $ttl) {
                    continue;
                }
                return $r['id'] ?? null;
            }
        }
        return null;
    }

    public function hasPtrRecord(int $domain_id, string $name): bool
    {
        $records = $this->backendProvider->getRecordsByZoneId($domain_id, 'PTR');
        foreach ($records as $r) {
            if ($r['name'] === $name) {
                return true;
            }
        }
        return false;
    }

    public function getSerialByZid(int $zid): string
    {
        $soa = $this->backendProvider->getSOARecord($zid);
        $fields = explode(' ', $soa);
        return $fields[2] ?? '';
    }

    public function getSerialsByZoneIds(array $zoneIds): array
    {
        if (empty($zoneIds)) {
            return [];
        }

        $serials = [];
        foreach ($zoneIds as $zid) {
            $soa = $this->backendProvider->getSOARecord($zid);
            $fields = explode(' ', $soa);
            $serials[$zid] = $fields[2] ?? '';
        }
        return $serials;
    }

    public function getRecordsByDomainId(int $domainId, ?string $recordType = null): array
    {
        return $this->backendProvider->getRecordsByZoneId($domainId, $recordType);
    }

    public function getFilteredRecords(
        int $zone_id,
        int $row_start,
        int $row_amount,
        string $sort_by,
        string $sort_direction,
        bool $include_comments,
        string $search_term = '',
        string $type_filter = '',
        string $content_filter = ''
    ): array {
        $records = $this->fetchAndFilterRecords($zone_id, $search_term, $type_filter, $content_filter);

        // Sort
        $records = ResultPaginator::sort($records, $sort_by, $sort_direction);

        // Paginate
        $records = ResultPaginator::paginate($records, $row_start, $row_amount);

        // Enrich with comments if requested
        if ($include_comments && !empty($records)) {
            $this->enrichRecordsWithComments($records);
        }

        return $records;
    }

    public function getFilteredRecordCount(
        int $zone_id,
        bool $include_comments,
        string $search_term = '',
        string $type_filter = '',
        string $content_filter = ''
    ): int {
        $records = $this->fetchAndFilterRecords($zone_id, $search_term, $type_filter, $content_filter);
        return count($records);
    }

    public function getNewRecordId(int $domainId, string $name, string $type, string $content): int|string|null
    {
        $records = $this->backendProvider->getRecordsByZoneId($domainId, $type);
        foreach ($records as $r) {
            if (strtolower($r['name']) === strtolower($name) && $r['type'] === $type && $r['content'] === $content) {
                return $r['id'] ?? null;
            }
        }
        return null;
    }

    public function getRecordById(int|string $recordId): ?array
    {
        $record = $this->backendProvider->getRecordById($recordId);
        if ($record === null) {
            return null;
        }
        $record['ordername'] = $record['ordername'] ?? '';
        $record['auth'] = $record['auth'] ?? 1;
        return $record;
    }

    public function getRRSetRecords(int $domainId, string $name, string $type): array
    {
        $name = strtolower($name);
        $records = $this->backendProvider->getRecordsByZoneId($domainId, $type);
        $result = [];
        foreach ($records as $r) {
            if (strtolower($r['name']) === $name) {
                $result[] = $r;
            }
        }
        usort($result, fn($a, $b) => strcmp($a['content'] ?? '', $b['content'] ?? ''));
        return $result;
    }

    private function fetchAndFilterRecords(
        int $zone_id,
        string $search_term,
        string $type_filter,
        string $content_filter
    ): array {
        $records = $this->backendProvider->getRecordsByZoneId($zone_id, $type_filter ?: null);

        // Filter ENT records (empty type)
        $records = array_filter($records, fn($r) => !empty($r['type']));
        $records = array_values($records);

        // Apply search term filter
        if (!empty($search_term)) {
            $records = ResultPaginator::filterByPattern($records, $search_term, ['name', 'content']);
        }

        // Apply content filter
        if (!empty($content_filter)) {
            $records = ResultPaginator::filterByPattern($records, $content_filter, ['content']);
        }

        return $records;
    }
}
