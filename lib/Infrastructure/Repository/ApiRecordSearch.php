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
use Psr\Log\LoggerInterface;
use Poweradmin\Domain\Database\DbCompat;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Port\RecordSearchInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Infrastructure\Utility\ResultPaginator;

/**
 * Record search over the PowerDNS API, enriched with the zone owner from
 * Poweradmin's zones table and filtered, sorted and paged in memory.
 */
final class ApiRecordSearch extends ApiSearchBase implements RecordSearchInterface
{
    private const SEARCH_LIMIT = 10000;
    private const MAX_COMMENT_ZONES = 50;

    /** @var array<string, array> Comment-matched rows per (query, mode, view, user), shared by the page and the count */
    private array $commentRows = [];

    public function __construct(
        PDO $db,
        DnsBackendProviderInterface $backendProvider,
        ZoneRepositoryInterface $zoneRepository,
        private readonly bool $recordCommentsEnabled = false,
        private readonly ?LoggerInterface $logger = null
    ) {
        parent::__construct($db, $backendProvider, $zoneRepository);
    }

    public function searchRecords(
        array $parameters,
        string $permissionView,
        ?int $userId,
        string $sortBy,
        string $sortDirection,
        bool $groupRecords,
        int $rowAmount,
        bool $includeComments,
        int $page
    ): array {
        $query = $parameters['query'] ?? '';
        if (empty($query) || !$parameters['records']) {
            return [];
        }

        // Only 'all' and 'own' grant visibility, as in the SQL search; any other level sees nothing
        if ($permissionView !== 'all' && $permissionView !== 'own') {
            return [];
        }

        $rawQuery = trim($query);
        $parameters = $this->preprocessSearchQuery($parameters);
        $query = $parameters['query'];

        $results = $this->backendProvider->searchDnsData($query, 'record', self::SEARCH_LIMIT);
        $records = $results['records'];

        // A reverse query is a second search whose new rows are appended
        $reverseQuery = $parameters['reverse_query'] ?? '';
        if (!empty($reverseQuery)) {
            $reverseResults = $this->backendProvider->searchDnsData($reverseQuery, 'record', self::SEARCH_LIMIT);
            $reverseRecords = $reverseResults['records'];
            if (!empty($reverseRecords)) {
                $seenKeys = [];
                foreach ($records as $r) {
                    $seenKeys[($r['name'] ?? '') . '|' . ($r['type'] ?? '') . '|' . ($r['content'] ?? '')] = true;
                }
                foreach ($reverseRecords as $rr) {
                    $key = ($rr['name'] ?? '') . '|' . ($rr['type'] ?? '') . '|' . ($rr['content'] ?? '');
                    if (!isset($seenKeys[$key])) {
                        $records[] = $rr;
                    }
                }
            }
        }

        // Wildcard off means an exact match on name or content, or on the reverse form
        if (isset($parameters['wildcard']) && !$parameters['wildcard']) {
            $records = array_values(array_filter($records, function ($record) use ($query, $reverseQuery) {
                $name = $record['name'] ?? '';
                $content = $record['content'] ?? '';
                return strcasecmp($name, $query) === 0
                    || strcasecmp($content, $query) === 0
                    || (!empty($reverseQuery) && (strcasecmp($name, $reverseQuery) === 0 || strcasecmp($content, $reverseQuery) === 0));
            }));
        }

        if ($this->recordCommentsEnabled && !empty($parameters['comments'])) {
            $exact = isset($parameters['wildcard']) && !$parameters['wildcard'];
            $records = $this->mergeCommentMatches($records, $this->commentMatches($rawQuery, $exact, $permissionView, $userId));
        }

        if (empty($records)) {
            return [];
        }

        $typeFilter = $parameters['type_filter'] ?? '';
        if (!empty($typeFilter)) {
            $records = ResultPaginator::filterByValue($records, 'type', strtoupper($typeFilter));
        }

        $contentFilter = $parameters['content_filter'] ?? '';
        if (!empty($contentFilter)) {
            $records = ResultPaginator::filterByPattern($records, $contentFilter, ['content']);
        }

        if ($permissionView === 'own') {
            if ($userId) {
                $records = $this->filterRecordsByZoneOwnership($records, $userId);
            } else {
                return [];
            }
        }

        $records = $this->enrichRecordsWithZoneOwnership($records);

        if ($groupRecords) {
            $seen = [];
            $records = array_values(array_filter($records, function ($record) use (&$seen) {
                $key = ($record['name'] ?? '') . '|' . ($record['content'] ?? '');
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;
                return true;
            }));
        }

        $records = ResultPaginator::sort($records, $sortBy, $sortDirection);

        $offset = ($page - 1) * $rowAmount;
        $records = ResultPaginator::paginate($records, $offset, $rowAmount);

        $result = [];
        foreach ($records as $record) {
            $result[] = [
                'id' => $record['id'] ?? 0,
                'domain_id' => $record['domain_id'] ?? 0,
                'name' => DnsIdnService::toUtf8($record['name'] ?? ''),
                'type' => $record['type'] ?? '',
                'content' => $record['content'] ?? '',
                'ttl' => $record['ttl'] ?? 0,
                'prio' => $record['prio'] ?? 0,
                'disabled' => (bool)DbCompat::boolFromDb($record['disabled'] ?? 0),
                'user_id' => $record['zone_owner_id'] ?? 0,
                'fullname' => $record['zone_owner_fullname'] ?? '',
            ];
        }

        if ($includeComments) {
            $result = $this->enrichSearchResultsWithComments($records, $result);
        }

        return $result;
    }

    public function getTotalRecords(array $parameters, string $permissionView, ?int $userId, bool $groupRecords): int
    {
        return count($this->searchRecords($parameters, $permissionView, $userId, 'name', 'ASC', $groupRecords, PHP_INT_MAX, false, 1));
    }

    /**
     * Comment-matched rows, computed once per request for a given search and user.
     */
    private function commentMatches(string $query, bool $exact, string $permissionView, ?int $userId): array
    {
        $key = $query . "\0" . (int)$exact . "\0" . $permissionView . "\0" . (int)$userId;
        if (!isset($this->commentRows[$key])) {
            $this->commentRows[$key] = $this->findRecordsByComment($query, $exact, $permissionView === 'own' ? $userId : null);
        }

        return $this->commentRows[$key];
    }

    /**
     * Records of every RRset whose comment matches. PowerDNS comments belong to
     * the whole RRset, so all of its records match, one zone fetch per zone.
     * With an owner id, zones that user cannot see are dropped before any fetch.
     */
    private function findRecordsByComment(string $query, bool $exact, ?int $ownerId): array
    {
        $hits = $this->backendProvider->searchDnsData($query, 'comment', self::SEARCH_LIMIT)['comments'] ?? [];
        $ownedIds = $ownerId !== null ? $this->ownedZoneIds($ownerId) : null;

        $rrsetsByZone = [];
        $zoneIds = [];
        foreach ($hits as $hit) {
            if ($exact && strcasecmp((string)($hit['comment'] ?? ''), $query) !== 0) {
                continue;
            }
            if ($ownedIds !== null && !in_array($hit['domain_id'] ?? 0, $ownedIds, true)) {
                continue;
            }
            $zoneName = (string)($hit['zone_name'] ?? '');
            $zoneIds[$zoneName] = $hit['domain_id'] ?? 0;
            $rrsetsByZone[$zoneName][strtolower(($hit['name'] ?? '') . '|' . ($hit['type'] ?? ''))] = true;
        }

        if (count($rrsetsByZone) > self::MAX_COMMENT_ZONES) {
            $this->logger?->warning('Comment search matched {zones} zones, listing the first {max}', [
                'zones' => count($rrsetsByZone),
                'max' => self::MAX_COMMENT_ZONES,
            ]);
            $rrsetsByZone = array_slice($rrsetsByZone, 0, self::MAX_COMMENT_ZONES, true);
        }

        $records = [];
        foreach ($rrsetsByZone as $zoneName => $rrsets) {
            foreach ($this->backendProvider->getZoneRecords($zoneIds[$zoneName], (string)$zoneName) as $record) {
                if (isset($rrsets[strtolower(($record['name'] ?? '') . '|' . ($record['type'] ?? ''))])) {
                    $record['zone_name'] = $zoneName;
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    /**
     * Append the comment matches that the name or content search did not already find.
     */
    private function mergeCommentMatches(array $records, array $commentRecords): array
    {
        $seen = [];
        foreach ($records as $record) {
            $seen[$this->recordKey($record)] = true;
        }
        foreach ($commentRecords as $record) {
            $key = $this->recordKey($record);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * The record's stable identity: its encoded id, else zone id, name, type, exact content and priority.
     */
    private function recordKey(array $record): string
    {
        $id = $record['id'] ?? '';
        if (is_string($id) && $id !== '') {
            return $id;
        }

        return ($record['domain_id'] ?? 0) . '|' . strtolower($record['name'] ?? '') . '|' . ($record['type'] ?? '')
            . '|' . ($record['content'] ?? '') . '|' . ($record['prio'] ?? 0);
    }

    /**
     * Keep only the records in zones the user owns directly or through a group.
     */
    private function filterRecordsByZoneOwnership(array $records, int $userId): array
    {
        $ownedDomainIds = $this->ownedZoneIds($userId);

        return array_values(array_filter($records, function ($record) use ($ownedDomainIds) {
            $domainId = $record['domain_id'] ?? 0;
            return in_array($domainId, $ownedDomainIds, true);
        }));
    }

    /**
     * Attach the zone owner's id and full name from Poweradmin's zones table.
     */
    private function enrichRecordsWithZoneOwnership(array $records): array
    {
        if (empty($records)) {
            return $records;
        }

        $domainIds = array_unique(array_filter(array_map(fn($r) => $r['domain_id'] ?? 0, $records)));
        if (empty($domainIds)) {
            return $records;
        }

        $placeholders = implode(',', array_fill(0, count($domainIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT " . $this->canonicalZoneId('z') . " AS domain_id, z.owner, u.id as user_id, u.username, u.fullname
             FROM zones z
             LEFT JOIN users u ON z.owner = u.id
             WHERE " . $this->canonicalZoneId('z') . " IN ($placeholders)"
        );
        foreach (array_values($domainIds) as $i => $domainId) {
            $stmt->bindValue($i + 1, (int)$domainId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $ownershipMap = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $did = (int)$row['domain_id'];
            $ownershipMap[$did] = [
                'zone_owner_id' => (int)($row['user_id'] ?? 0),
                'zone_owner_fullname' => $row['fullname'] ?? '',
                'zone_owner_username' => $row['username'] ?? '',
            ];
        }

        foreach ($records as &$record) {
            $did = $record['domain_id'] ?? 0;
            if (isset($ownershipMap[$did])) {
                $record['zone_owner_id'] = $ownershipMap[$did]['zone_owner_id'];
                $record['zone_owner_fullname'] = $ownershipMap[$did]['zone_owner_fullname'];
            } else {
                $record['zone_owner_id'] = 0;
                $record['zone_owner_fullname'] = '';
            }
        }
        unset($record);

        return $records;
    }

    /**
     * Fill in the RRset comment of each result row from its zone's rrsets.
     */
    private function enrichSearchResultsWithComments(array $sourceRecords, array $formattedResult): array
    {
        $apiComments = $this->loadApiRRsetComments($sourceRecords);

        foreach ($formattedResult as $i => &$row) {
            $name = $sourceRecords[$i]['name'] ?? '';
            $type = $sourceRecords[$i]['type'] ?? '';
            $zoneName = $sourceRecords[$i]['zone_name'] ?? '';
            $zoneKey = $name . '|' . $type;
            $row['comment'] = $apiComments[$zoneName][$zoneKey] ?? '';
        }
        unset($row);

        return $formattedResult;
    }

    private function loadApiRRsetComments(array $sourceRecords): array
    {
        $zoneComments = [];
        foreach ($sourceRecords as $record) {
            $zoneName = $record['zone_name'] ?? '';
            if ($zoneName === '' || isset($zoneComments[$zoneName])) {
                continue;
            }

            $zoneComments[$zoneName] = [];
            $zoneRecords = $this->backendProvider->getZoneRecords($record['domain_id'] ?? 0, $zoneName);
            foreach ($zoneRecords as $zr) {
                $key = ($zr['name'] ?? '') . '|' . ($zr['type'] ?? '');
                if (!empty($zr['api_comment']) && !isset($zoneComments[$zoneName][$key])) {
                    $zoneComments[$zoneName][$key] = $zr['api_comment'];
                }
            }
        }
        return $zoneComments;
    }
}
