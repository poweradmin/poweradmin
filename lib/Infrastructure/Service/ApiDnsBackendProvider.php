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

namespace Poweradmin\Infrastructure\Service;

use PDO;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\TableNameService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PowerDNS REST API-based DNS backend provider.
 *
 * Performs DNS write operations through the PowerDNS API while reading
 * from the database (PowerDNS syncs data to DB in real-time).
 * This is the experimental API backend.
 */
class ApiDnsBackendProvider implements DnsBackendProvider
{
    private PowerdnsApiClient $client;
    private PDO $db;
    private ConfigurationInterface $config;
    private TableNameService $tableNameService;
    private LoggerInterface $logger;

    public function __construct(PowerdnsApiClient $client, PDO $db, ConfigurationInterface $config, ?LoggerInterface $logger = null)
    {
        $this->client = $client;
        $this->db = $db;
        $this->config = $config;
        $this->tableNameService = new TableNameService($config);
        $this->logger = $logger ?? new NullLogger();
    }

    // ---------------------------------------------------------------
    // Zone operations
    // ---------------------------------------------------------------

    public function createZone(string $domain, string $type, string $slaveMaster = ''): int|false
    {
        $apiName = self::ensureTrailingDot($domain);

        $zoneData = [
            'name' => $apiName,
            'kind' => $type,
            'nameservers' => [],
        ];

        if ($type === 'SLAVE' && $slaveMaster !== '') {
            $zoneData['masters'] = [$slaveMaster];
        }

        $result = $this->client->createZoneWithData($zoneData);
        if ($result === null) {
            return false;
        }

        // After API creates the zone, PowerDNS writes it to the database.
        // We need the domain ID for Poweradmin's ownership system.
        $id = $this->lookupDomainIdByName($domain);
        if ($id === false) {
            throw new \Poweradmin\Domain\Error\ZoneIdNotFoundException(
                sprintf("Zone '%s' created via API but DB ID not found after retries", $domain)
            );
        }

        return $id;
    }

    public function deleteZone(int $domainId, string $zoneName): bool
    {
        $apiName = self::ensureTrailingDot($zoneName);
        $zone = new Zone($apiName);

        return $this->client->deleteZone($zone);
    }

    public function updateZoneType(int $domainId, string $type): bool
    {
        $zoneName = $this->getDomainNameById($domainId);
        if ($zoneName === null) {
            return false;
        }

        $apiName = self::ensureTrailingDot($zoneName);
        $data = ['kind' => $type];

        if ($type !== 'SLAVE') {
            $data['masters'] = [];
        }

        return $this->client->updateZoneProperties($apiName, $data);
    }

    public function updateZoneMaster(int $domainId, string $masterIp): bool
    {
        $zoneName = $this->getDomainNameById($domainId);
        if ($zoneName === null) {
            return false;
        }

        $apiName = self::ensureTrailingDot($zoneName);

        return $this->client->updateZoneProperties($apiName, ['masters' => [$masterIp]]);
    }

    public function updateZoneAccount(int $domainId, string $account): bool
    {
        $zoneName = $this->getDomainNameById($domainId);
        if ($zoneName === null) {
            return false;
        }

        $apiName = self::ensureTrailingDot($zoneName);

        return $this->client->updateZoneProperties($apiName, ['account' => $account]);
    }

    // ---------------------------------------------------------------
    // Record operations
    // ---------------------------------------------------------------

    public function addRecord(int $domainId, string $name, string $type, string $content, int $ttl, int $prio): bool
    {
        $zoneName = $this->getDomainNameById($domainId);
        if ($zoneName === null) {
            return false;
        }

        $apiZoneName = self::ensureTrailingDot($zoneName);
        $apiRecordName = self::ensureTrailingDot($name);

        // Read existing RRset from the API (not DB) to avoid stale reads.
        // DB sync is not instant, so rapid successive adds to the same RRset
        // (e.g., multiple NS records from a template) would read stale state
        // from the DB and the second REPLACE would clobber the first.
        $rrsetData = $this->getRRsetFromApi($apiZoneName, $apiRecordName, $type);
        if ($rrsetData === null) {
            $this->logger->error("Failed to fetch current RRset for '{name} {type}' from API - aborting to prevent data loss", [
                'name' => $apiRecordName, 'type' => $type,
            ]);
            return false;
        }

        // Build the full RRset including existing records + new record.
        // SOA is a singleton type - replace the existing record instead of appending.
        if ($type === 'SOA') {
            $records = [
                [
                    'content' => $this->formatRecordContent($type, $content, $prio),
                    'disabled' => false,
                ],
            ];
        } else {
            $records = $rrsetData['records'];
            $records[] = [
                'content' => $this->formatRecordContent($type, $content, $prio),
                'disabled' => false,
            ];
        }

        $rrset = [
            'name' => $apiRecordName,
            'type' => $type,
            'ttl' => $ttl,
            'changetype' => 'REPLACE',
            'records' => $records,
        ];

        return $this->client->patchZoneRRsets($apiZoneName, [$rrset]);
    }

    public function addRecordGetId(int $domainId, string $name, string $type, string $content, int $ttl, int $prio): ?int
    {
        if (!$this->addRecord($domainId, $name, $type, $content, $ttl, $prio)) {
            return null;
        }

        // After API writes, PowerDNS syncs to DB. Read back the record ID.
        // PowerDNS stores MX/SRV with priority in the prio column and bare
        // content in the content column, so we must match on both fields
        // rather than using the API-formatted (priority-prepended) content.
        $id = $this->lookupRecordId($domainId, $name, $type, $content, $prio);
        if ($id === null) {
            throw new \Poweradmin\Domain\Error\RecordIdNotFoundException(
                sprintf("Record '%s %s' created via API but DB ID not found after retries", $name, $type)
            );
        }

        return $id;
    }

    public function createRecordAtomic(int $domainId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled = 0): ?int
    {
        $id = $this->addRecordGetId($domainId, $name, $type, $content, $ttl, $prio);
        if ($id === null) {
            return null;
        }

        if ($disabled === 1) {
            if (!$this->editRecord($id, $name, $type, $content, $ttl, $prio, $disabled)) {
                $this->logger->error('Failed to set disabled flag for record ID {id}, rolling back', ['id' => $id]);
                $this->deleteRecord($id);
                return null;
            }

            // PowerDNS may reassign the DB record ID after the PATCH that sets the
            // disabled flag. Re-lookup the current ID so callers get the valid one.
            $newId = $this->lookupRecordId($domainId, $name, $type, $content, $prio);
            if ($newId !== null) {
                $id = $newId;
            }
        }

        return $id;
    }

    public function editRecord(int $recordId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled): bool
    {
        // Read old record from DB to find zone and old name/type/content
        $oldRecord = $this->getRecordFromDb($recordId);
        if ($oldRecord === null) {
            return false;
        }

        $domainId = (int)$oldRecord['domain_id'];
        $zoneName = $this->getDomainNameById($domainId);
        if ($zoneName === null) {
            return false;
        }

        $apiZoneName = self::ensureTrailingDot($zoneName);
        $oldName = $oldRecord['name'];
        $oldType = $oldRecord['type'];
        $oldApiContent = $this->formatRecordContent($oldType, $oldRecord['content'], (int)$oldRecord['prio']);
        $rrsets = [];

        // If name or type changed, we need to remove from old RRset and add to new RRset
        if ($oldName !== $name || $oldType !== $type) {
            // Remove from old RRset (read from API to avoid stale DB reads)
            $oldRRsetData = $this->getRRsetFromApi($apiZoneName, self::ensureTrailingDot($oldName), $oldType);
            if ($oldRRsetData === null) {
                $this->logger->error("Failed to fetch old RRset for '{name} {type}' from API - aborting to prevent data loss", [
                    'name' => $oldName, 'type' => $oldType,
                ]);
                return false;
            }

            $remainingRecords = [];
            $found = false;
            foreach ($oldRRsetData['records'] as $r) {
                if (!$found && self::contentMatchesApi($r['content'], $oldApiContent)) {
                    $found = true;
                    continue;
                }
                $remainingRecords[] = $r;
            }

            if (empty($remainingRecords)) {
                $rrsets[] = [
                    'name' => self::ensureTrailingDot($oldName),
                    'type' => $oldType,
                    'changetype' => 'DELETE',
                ];
            } else {
                $rrsets[] = [
                    'name' => self::ensureTrailingDot($oldName),
                    'type' => $oldType,
                    'ttl' => $oldRRsetData['ttl'],
                    'changetype' => 'REPLACE',
                    'records' => $remainingRecords,
                ];
            }

            // Add to new RRset (read from API to avoid stale DB reads)
            $newRRsetData = $this->getRRsetFromApi($apiZoneName, self::ensureTrailingDot($name), $type);
            if ($newRRsetData === null) {
                $this->logger->error("Failed to fetch new RRset for '{name} {type}' from API - aborting to prevent data loss", [
                    'name' => $name, 'type' => $type,
                ]);
                return false;
            }

            $newRecords = $newRRsetData['records'];
            $newRecords[] = [
                'content' => $this->formatRecordContent($type, $content, $prio),
                'disabled' => (bool)$disabled,
            ];

            $rrsets[] = [
                'name' => self::ensureTrailingDot($name),
                'type' => $type,
                'ttl' => $ttl,
                'changetype' => 'REPLACE',
                'records' => $newRecords,
            ];
        } else {
            // Same name+type: rebuild the RRset with the modified record
            $rrsetData = $this->getRRsetFromApi($apiZoneName, self::ensureTrailingDot($name), $type);
            if ($rrsetData === null) {
                $this->logger->error("Failed to fetch RRset for '{name} {type}' from API - aborting to prevent data loss", [
                    'name' => $name, 'type' => $type,
                ]);
                return false;
            }

            $records = [];
            $found = false;
            foreach ($rrsetData['records'] as $r) {
                if (!$found && self::contentMatchesApi($r['content'], $oldApiContent)) {
                    $found = true;
                    $records[] = [
                        'content' => $this->formatRecordContent($type, $content, $prio),
                        'disabled' => (bool)$disabled,
                    ];
                } else {
                    $records[] = $r;
                }
            }

            if (!$found) {
                // Old record not found in API - append as new
                $records[] = [
                    'content' => $this->formatRecordContent($type, $content, $prio),
                    'disabled' => (bool)$disabled,
                ];
            }

            $rrsets[] = [
                'name' => self::ensureTrailingDot($name),
                'type' => $type,
                'ttl' => $ttl,
                'changetype' => 'REPLACE',
                'records' => $records,
            ];
        }

        return $this->client->patchZoneRRsets($apiZoneName, $rrsets);
    }

    public function deleteRecord(int $recordId): bool
    {
        // Read the record from DB to identify it (name, type, content)
        $record = $this->getRecordFromDb($recordId);
        if ($record === null) {
            return false;
        }

        $domainId = (int)$record['domain_id'];
        $name = $record['name'];
        $type = $record['type'];

        $zoneName = $this->getDomainNameById($domainId);
        if ($zoneName === null) {
            return false;
        }

        $apiZoneName = self::ensureTrailingDot($zoneName);
        $apiRecordName = self::ensureTrailingDot($name);

        // Read sibling records from the API (not DB) to avoid stale reads
        $rrsetData = $this->getRRsetFromApi($apiZoneName, $apiRecordName, $type);
        if ($rrsetData === null) {
            $this->logger->error("Failed to fetch current RRset for '{name} {type}' from API - aborting to prevent data loss", [
                'name' => $name, 'type' => $type,
            ]);
            return false;
        }

        // Remove the target record by matching its content (normalized for trailing dots)
        $deleteContent = $this->formatRecordContent($type, $record['content'], (int)$record['prio']);
        $remainingRecords = [];
        $found = false;
        foreach ($rrsetData['records'] as $r) {
            if (!$found && self::contentMatchesApi($r['content'], $deleteContent)) {
                $found = true;
                continue;
            }
            $remainingRecords[] = $r;
        }

        if (empty($remainingRecords)) {
            // Last record in RRset - delete entire RRset
            $rrset = [
                'name' => $apiRecordName,
                'type' => $type,
                'changetype' => 'DELETE',
            ];
        } else {
            // Replace RRset without the deleted record
            $rrset = [
                'name' => $apiRecordName,
                'type' => $type,
                'ttl' => $rrsetData['ttl'],
                'changetype' => 'REPLACE',
                'records' => $remainingRecords,
            ];
        }

        return $this->client->patchZoneRRsets($apiZoneName, [$rrset]);
    }

    public function deleteRecordsByDomainId(int $domainId): bool
    {
        // Zone deletion via API handles this automatically
        return true;
    }

    // ---------------------------------------------------------------
    // Zone read methods
    // ---------------------------------------------------------------

    public function zoneExists(string $zoneName): bool
    {
        $apiName = self::ensureTrailingDot($zoneName);
        $zoneData = $this->client->getZone($apiName);
        return $zoneData !== null;
    }

    public function getZoneById(int $domainId): ?array
    {
        $zoneName = $this->getDomainNameById($domainId);
        if ($zoneName === null) {
            return null;
        }
        $zone = $this->getZoneByName($zoneName);
        if ($zone === null) {
            return null;
        }
        $zone['id'] = $domainId;
        return $zone;
    }

    public function getZoneNameById(int $domainId): ?string
    {
        return $this->getDomainNameById($domainId);
    }

    public function getZoneIdByName(string $zoneName): ?int
    {
        if (empty($zoneName)) {
            return null;
        }
        return $this->lookupDomainIdByNameDirect($zoneName);
    }

    public function getZoneTypeById(int $domainId): string
    {
        $zone = $this->getZoneById($domainId);
        return $zone ? ($zone['type'] ?: 'NATIVE') : 'NATIVE';
    }

    public function getZoneMasterById(int $domainId): ?string
    {
        $zone = $this->getZoneById($domainId);
        if ($zone === null || strtoupper($zone['type']) !== 'SLAVE') {
            return null;
        }
        return $zone['master'] ?: null;
    }

    // ---------------------------------------------------------------
    // Record read methods
    // ---------------------------------------------------------------

    public function getRecordById(int $recordId): ?array
    {
        $record = $this->getRecordFromDb($recordId);
        if ($record === null) {
            return null;
        }
        if (empty($record['type']) || empty($record['content'])) {
            return null;
        }
        return [
            'id' => (int)$record['id'],
            'domain_id' => (int)$record['domain_id'],
            'name' => $record['name'],
            'type' => $record['type'],
            'content' => $record['content'],
            'ttl' => (int)$record['ttl'],
            'prio' => (int)$record['prio'],
            'disabled' => (int)$record['disabled'],
        ];
    }

    public function getZoneIdFromRecordId(int $recordId): int
    {
        $record = $this->getRecordFromDb($recordId);
        return $record ? (int)$record['domain_id'] : 0;
    }

    public function countZoneRecords(int $domainId): int
    {
        $zoneName = $this->getDomainNameById($domainId);
        if ($zoneName === null) {
            return 0;
        }
        $records = $this->getZoneRecords($domainId, $zoneName);
        return count($records);
    }

    public function recordExists(int $domainId, string $name, string $type, string $content): bool
    {
        $zoneName = $this->getDomainNameById($domainId);
        if ($zoneName === null) {
            return false;
        }
        $records = $this->getZoneRecords($domainId, $zoneName);
        foreach ($records as $r) {
            if ($r['name'] === $name && $r['type'] === $type && $r['content'] === $content) {
                return true;
            }
        }
        return false;
    }

    public function getRecordsByZoneId(int $domainId, ?string $type = null): array
    {
        $zoneName = $this->getDomainNameById($domainId);
        if ($zoneName === null) {
            return [];
        }
        $records = $this->getZoneRecords($domainId, $zoneName);
        if ($type !== null) {
            $records = array_values(array_filter($records, fn($r) => $r['type'] === $type));
        }
        return $records;
    }

    public function getSOARecord(int $domainId): string
    {
        $zoneName = $this->getDomainNameById($domainId);
        if ($zoneName === null) {
            return '';
        }
        $apiName = self::ensureTrailingDot($zoneName);
        $zoneData = $this->client->getZone($apiName);
        if ($zoneData === null) {
            return '';
        }
        foreach ($zoneData['rrsets'] ?? [] as $rrset) {
            if (($rrset['type'] ?? '') === 'SOA') {
                $record = $rrset['records'][0] ?? null;
                if ($record) {
                    $content = $record['content'] ?? '';
                    // Strip trailing dots from the two hostname fields
                    $parts = explode(' ', $content);
                    if (count($parts) >= 7) {
                        $parts[0] = rtrim($parts[0], '.');
                        $parts[1] = rtrim($parts[1], '.');
                        return implode(' ', $parts);
                    }
                    return $content;
                }
            }
        }
        return '';
    }

    public function getBestMatchingReverseZoneId(string $reverseName): int
    {
        $zones = $this->getZones();
        $match = 72;
        $foundId = -1;

        foreach ($zones as $zone) {
            if (!str_ends_with($zone['name'], '.arpa')) {
                continue;
            }
            $pos = stripos($reverseName, $zone['name']);
            if ($pos !== false && $pos < $match) {
                $match = $pos;
                $foundId = (int)$zone['id'];
            }
        }
        return $foundId;
    }

    // ---------------------------------------------------------------
    // Zone list operations
    // ---------------------------------------------------------------

    public function getZones(): array
    {
        $apiZones = $this->client->getAllZones();

        $zones = [];
        foreach ($apiZones as $zone) {
            $name = rtrim($zone->getName(), '.');
            $zones[] = [
                'id' => 0,
                'name' => $name,
                'type' => '', // getAllZones() doesn't return kind
                'master' => '',
                'dnssec' => $zone->isSecured(),
            ];
        }

        // Enrich with type/master from individual zone lookups if needed.
        // For list views, we batch-fetch zone details to get kind/master.
        // Use a pragmatic approach: do a single DB query if available, else leave empty.
        if (!empty($zones)) {
            $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
            $stmt = $this->db->query("SELECT id, name, type, master FROM $domainsTable");
            $dbZones = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dbZones[$row['name']] = $row;
            }

            foreach ($zones as &$zone) {
                if (isset($dbZones[$zone['name']])) {
                    $zone['id'] = (int)$dbZones[$zone['name']]['id'];
                    $zone['type'] = strtoupper($dbZones[$zone['name']]['type'] ?? '');
                    $zone['master'] = $dbZones[$zone['name']]['master'] ?? '';
                }
            }
            unset($zone);
        }

        return $zones;
    }

    public function getZoneByName(string $zoneName): ?array
    {
        $apiName = self::ensureTrailingDot($zoneName);
        $zoneData = $this->client->getZone($apiName);
        if ($zoneData === null) {
            return null;
        }

        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $stmt = $this->db->prepare("SELECT id FROM $domainsTable WHERE name = :name");
        $stmt->execute([':name' => $zoneName]);
        $id = $stmt->fetchColumn();

        return [
            'id' => $id !== false ? (int)$id : 0,
            'name' => $zoneName,
            'type' => strtoupper($zoneData['kind'] ?? ''),
            'master' => implode(',', $zoneData['masters'] ?? []),
            'dnssec' => $zoneData['dnssec'] ?? false,
        ];
    }

    // ---------------------------------------------------------------
    // Record read operations
    // ---------------------------------------------------------------

    public function getZoneRecords(int $domainId, string $zoneName): array
    {
        $apiName = self::ensureTrailingDot($zoneName);
        $zoneData = $this->client->getZone($apiName);
        if ($zoneData === null) {
            return [];
        }

        $records = [];
        foreach ($zoneData['rrsets'] ?? [] as $rrset) {
            $type = $rrset['type'] ?? '';
            if ($type === '' || $type === null) {
                continue; // Skip ENT records
            }

            $name = rtrim($rrset['name'] ?? '', '.');
            $ttl = $rrset['ttl'] ?? 3600;

            foreach ($rrset['records'] ?? [] as $record) {
                $content = $record['content'] ?? '';
                $prio = 0;

                // Extract priority from content for MX/SRV
                if ($type === 'MX' || $type === 'SRV') {
                    $parts = explode(' ', $content, 2);
                    if (count($parts) === 2) {
                        $prio = (int)$parts[0];
                        $content = $parts[1];
                    }
                }

                // Strip trailing dot from hostname-type content for DB format
                $content = $this->stripTrailingDotFromContent($type, $content);

                $records[] = [
                    'id' => 0, // Will be resolved below
                    'domain_id' => $domainId,
                    'name' => $name,
                    'type' => $type,
                    'content' => $content,
                    'ttl' => (int)$ttl,
                    'prio' => $prio,
                    'disabled' => ($record['disabled'] ?? false) ? 1 : 0,
                ];
            }
        }

        // Resolve record IDs from the database
        $this->resolveRecordIds($records, $domainId);

        return $records;
    }

    // ---------------------------------------------------------------
    // Search operations
    // ---------------------------------------------------------------

    public function searchDnsData(string $query, string $objectType = 'all', int $max = 100): array
    {
        // PowerDNS search API requires wildcard characters for partial matching
        $wildcardQuery = '*' . $query . '*';
        $apiResults = $this->client->searchData($wildcardQuery, $objectType, $max);

        $zones = [];
        $records = [];

        foreach ($apiResults as $result) {
            $objType = $result['object_type'] ?? '';
            $name = rtrim($result['name'] ?? '', '.');

            if ($objType === 'zone') {
                $zones[] = [
                    'name' => $name,
                    'type' => strtoupper($result['kind'] ?? ''),
                ];
            } elseif ($objType === 'record') {
                $content = $result['content'] ?? '';
                $type = $result['type'] ?? '';
                $prio = 0;

                if ($type === 'MX' || $type === 'SRV') {
                    $parts = explode(' ', $content, 2);
                    if (count($parts) === 2) {
                        $prio = (int)$parts[0];
                        $content = $parts[1];
                    }
                }

                $content = $this->stripTrailingDotFromContent($type, $content);
                $zoneName = rtrim($result['zone'] ?? '', '.');

                $records[] = [
                    'id' => 0,
                    'domain_id' => 0,
                    'name' => $name,
                    'type' => $type,
                    'content' => $content,
                    'ttl' => (int)($result['ttl'] ?? 0),
                    'prio' => $prio,
                    'disabled' => ($result['disabled'] ?? false) ? 1 : 0,
                    'zone_name' => $zoneName,
                ];
            }
        }

        // Build domain name->id lookup from DB (used for both zones and records)
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $dbZones = [];
        if (!empty($zones) || !empty($records)) {
            $stmt = $this->db->query("SELECT id, name, type FROM $domainsTable");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dbZones[$row['name']] = $row;
            }
        }

        // Enrich zones with IDs from DB
        if (!empty($zones)) {
            foreach ($zones as &$zone) {
                if (isset($dbZones[$zone['name']])) {
                    $zone['id'] = (int)$dbZones[$zone['name']]['id'];
                    if (empty($zone['type'])) {
                        $zone['type'] = strtoupper($dbZones[$zone['name']]['type'] ?? '');
                    }
                }
            }
            unset($zone);
        }

        // Enrich records with domain_id and id from DB
        if (!empty($records)) {
            $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

            // Set domain_id from zone name lookup
            $domainIds = [];
            foreach ($records as &$record) {
                $zoneName = $record['zone_name'] ?? '';
                $record['domain_id'] = isset($dbZones[$zoneName]) ? (int)$dbZones[$zoneName]['id'] : 0;
                if ($record['domain_id'] > 0) {
                    $domainIds[$record['domain_id']] = true;
                }
            }
            unset($record);

            // Batch-resolve record IDs for all matched domain IDs
            if (!empty($domainIds)) {
                $placeholders = implode(',', array_fill(0, count($domainIds), '?'));
                $stmt = $this->db->prepare(
                    "SELECT id, domain_id, name, type, content, prio FROM $recordsTable
                     WHERE domain_id IN ($placeholders) AND type IS NOT NULL AND type != ''"
                );
                $stmt->execute(array_keys($domainIds));

                // Build lookup map with array-of-IDs for duplicate handling
                $idMap = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $key = $row['domain_id'] . '|' . $row['name'] . '|' . $row['type'] . '|' . $row['content'] . '|' . (int)$row['prio'];
                    $idMap[$key][] = (int)$row['id'];
                }

                foreach ($records as &$record) {
                    $key = $record['domain_id'] . '|' . $record['name'] . '|' . $record['type'] . '|' . $record['content'] . '|' . $record['prio'];
                    $record['id'] = !empty($idMap[$key]) ? array_shift($idMap[$key]) : 0;
                }
                unset($record);
            }
        }

        return ['zones' => $zones, 'records' => $records];
    }

    // ---------------------------------------------------------------
    // SOA operations
    // ---------------------------------------------------------------

    public function updateSOASerial(int $domainId): bool
    {
        // In API mode, PowerDNS handles SOA serial increments automatically
        // via the soa_edit_api zone setting. No action needed.
        return true;
    }

    // ---------------------------------------------------------------
    // Supermaster (autoprimary) operations
    // ---------------------------------------------------------------

    public function addSupermaster(string $masterIp, string $nsName, string $account): bool
    {
        return $this->client->addAutoprimary($masterIp, $nsName, $account);
    }

    public function deleteSupermaster(string $masterIp, string $nsName): bool
    {
        return $this->client->deleteAutoprimary($masterIp, $nsName);
    }

    public function getSupermasters(): array
    {
        $apiResult = $this->client->getAutoprimaries();

        $supermasters = [];
        foreach ($apiResult as $entry) {
            $supermasters[] = [
                'master_ip' => $entry['ip'] ?? '',
                'ns_name' => $entry['nameserver'] ?? '',
                'account' => $entry['account'] ?? '',
            ];
        }

        return $supermasters;
    }

    public function updateSupermaster(string $oldMasterIp, string $oldNsName, string $newMasterIp, string $newNsName, string $account): bool
    {
        // PowerDNS autoprimary API has no update endpoint, so we use add-then-delete
        // to avoid data loss if the add step fails (delete-then-add would lose the
        // original entry on a transient API error).
        $sameEntry = ($oldMasterIp === $newMasterIp && $oldNsName === $newNsName);

        if ($sameEntry) {
            // Only the account changed - must delete and re-add (no partial update in API).
            // Fetch the old account first so we can restore it on failure.
            $oldAccount = $this->getAutoprimaryAccount($oldMasterIp, $oldNsName);

            if (!$this->client->deleteAutoprimary($oldMasterIp, $oldNsName)) {
                return false;
            }
            if (!$this->client->addAutoprimary($newMasterIp, $newNsName, $account)) {
                // Re-add the old entry with original account as best-effort recovery
                $this->client->addAutoprimary($oldMasterIp, $oldNsName, $oldAccount);
                return false;
            }
            return true;
        }

        // Different IP or nameserver: add new first, then delete old
        if (!$this->client->addAutoprimary($newMasterIp, $newNsName, $account)) {
            return false;
        }

        if (!$this->client->deleteAutoprimary($oldMasterIp, $oldNsName)) {
            // New entry exists but old wasn't removed - not ideal but data is safe.
            // Clean up the new entry to keep state consistent.
            $this->client->deleteAutoprimary($newMasterIp, $newNsName);
            return false;
        }

        return true;
    }

    // ---------------------------------------------------------------
    // Capability
    // ---------------------------------------------------------------

    public function isApiBackend(): bool
    {
        return true;
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    /**
     * Look up the current account value for an autoprimary entry.
     */
    private function getAutoprimaryAccount(string $ip, string $nameserver): string
    {
        foreach ($this->client->getAutoprimaries() as $entry) {
            if (($entry['ip'] ?? '') === $ip && ($entry['nameserver'] ?? '') === $nameserver) {
                return $entry['account'] ?? '';
            }
        }
        return '';
    }

    /**
     * Ensure a domain name has a trailing dot (required by PowerDNS API).
     */
    private static function ensureTrailingDot(string $name): string
    {
        return str_ends_with($name, '.') ? $name : $name . '.';
    }

    /**
     * Compare record content from the API with DB-formatted content.
     *
     * PowerDNS stores hostname-type content (CNAME, NS, MX, PTR, SRV)
     * without a trailing dot in the DB, but the API returns it with one.
     * This method normalizes both sides by stripping trailing dots.
     */
    private static function contentMatchesApi(string $apiContent, string $dbFormattedContent): bool
    {
        return rtrim($apiContent, '.') === rtrim($dbFormattedContent, '.');
    }

    /**
     * Format record content for the PowerDNS API.
     *
     * Poweradmin stores MX/SRV priority in a separate `prio` column, but the
     * PowerDNS API expects it as part of the content field. This method
     * prepends the priority when needed.
     *
     * For MX: content = "mail.example.com." -> "10 mail.example.com."
     * For SRV: content = "0 5060 sip.example.com." -> "10 0 5060 sip.example.com."
     *   (SRV content already contains weight+port, priority is prepended)
     */
    private function formatRecordContent(string $type, string $content, int $prio): string
    {
        if ($type === 'MX' || $type === 'SRV') {
            // Poweradmin stores priority in the prio column, content is bare.
            // MX content = "mail.example.com." -> "10 mail.example.com."
            // SRV content = "0 5060 sip.example.com." -> "10 0 5060 sip.example.com."
            return $prio . ' ' . $content;
        }

        if ($type === 'SOA') {
            // SOA content: "ns1.example.com hostmaster.example.com serial refresh retry expire minimum"
            // PowerDNS API requires FQDN format with trailing dots on the two hostname fields.
            $parts = explode(' ', $content);
            if (count($parts) >= 7) {
                $parts[0] = self::ensureTrailingDot($parts[0]);
                $parts[1] = self::ensureTrailingDot($parts[1]);
                return implode(' ', $parts);
            }
        }

        return $content;
    }

    /**
     * Look up domain ID by name from the database without retries.
     * Used for read operations where the zone should already exist.
     */
    private function lookupDomainIdByNameDirect(string $zoneName): ?int
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $stmt = $this->db->prepare("SELECT id FROM $domainsTable WHERE name = :name");
        $stmt->execute([':name' => $zoneName]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * Look up domain ID by name from the database.
     *
     * After the API creates a zone, PowerDNS writes it to the DB.
     * We need the DB-assigned ID for Poweradmin's ownership tracking.
     */
    private function lookupDomainIdByName(string $domain): int|false
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        // PowerDNS may need a moment to write to DB.
        // Use exponential backoff: 100ms, 200ms, 400ms, 800ms, 1600ms (total ~3.1s)
        $maxRetries = 5;
        $sleepMs = 100000; // 100ms initial
        for ($i = 0; $i < $maxRetries; $i++) {
            $stmt = $this->db->prepare("SELECT id FROM $domainsTable WHERE name = :name");
            $stmt->execute([':name' => $domain]);
            $id = $stmt->fetchColumn();

            if ($id !== false) {
                return (int)$id;
            }

            if ($i < $maxRetries - 1) {
                usleep($sleepMs);
                $sleepMs *= 2;
            }
        }

        $this->logger->error("Failed to find domain ID for '{domain}' after API creation (retried {retries} times)", [
            'domain' => $domain, 'retries' => $maxRetries,
        ]);
        return false;
    }

    /**
     * Look up a record ID from the database after API creates it.
     *
     * Uses (domain_id, name, type, content, prio) to match. This is important
     * for MX/SRV where PowerDNS stores priority in the prio column and bare
     * content in the content column (not the API-formatted version).
     */
    private function lookupRecordId(int $domainId, string $name, string $type, string $content, int $prio = 0): ?int
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

        // Use exponential backoff: 100ms, 200ms, 400ms, 800ms, 1600ms (total ~3.1s)
        $maxRetries = 5;
        $sleepMs = 100000; // 100ms initial
        for ($i = 0; $i < $maxRetries; $i++) {
            $stmt = $this->db->prepare("SELECT id FROM $recordsTable WHERE domain_id = :did AND name = :name AND type = :type AND content = :content AND prio = :prio ORDER BY id DESC LIMIT 1");
            $stmt->bindValue(':did', $domainId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->bindValue(':content', $content, PDO::PARAM_STR);
            $stmt->bindValue(':prio', $prio, PDO::PARAM_INT);
            $stmt->execute();
            $id = $stmt->fetchColumn();

            if ($id !== false) {
                return (int)$id;
            }

            if ($i < $maxRetries - 1) {
                usleep($sleepMs);
                $sleepMs *= 2;
            }
        }

        $this->logger->error("Failed to find record ID for '{name} {type} {content}' after API creation (retried {retries} times)", [
            'name' => $name, 'type' => $type, 'content' => $content, 'retries' => $maxRetries,
        ]);
        return null;
    }

    /**
     * Get domain name by ID from the database.
     */
    private function getDomainNameById(int $domainId): ?string
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->prepare("SELECT name FROM $domainsTable WHERE id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $name = $stmt->fetchColumn();

        return $name !== false ? $name : null;
    }

    /**
     * Get a single record from the database by ID.
     */
    private function getRecordFromDb(int $recordId): ?array
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT id, domain_id, name, type, content, ttl, prio, disabled FROM $recordsTable WHERE id = :id");
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        return $record !== false ? $record : null;
    }

    /**
     * Get the current records for an RRset directly from the PowerDNS API.
     *
     * Returns records in API format: [['content' => ..., 'disabled' => ...], ...]
     * This is preferred over DB reads for building REPLACE payloads because
     * the API always reflects the authoritative state immediately.
     */
    /**
     * @return array{records: array, ttl: int}|null null on API failure
     */
    private function getRRsetFromApi(string $apiZoneName, string $apiRecordName, string $type): ?array
    {
        $zoneData = $this->client->getZone($apiZoneName);
        if ($zoneData === null) {
            return null;
        }

        foreach ($zoneData['rrsets'] ?? [] as $rrset) {
            if ($rrset['name'] === $apiRecordName && $rrset['type'] === $type) {
                $records = [];
                foreach ($rrset['records'] ?? [] as $record) {
                    $records[] = [
                        'content' => $record['content'],
                        'disabled' => $record['disabled'] ?? false,
                    ];
                }
                return [
                    'records' => $records,
                    'ttl' => $rrset['ttl'] ?? 3600,
                ];
            }
        }

        return ['records' => [], 'ttl' => 3600];
    }

    /**
     * Strip trailing dot from hostname-type record content.
     *
     * PowerDNS API returns content with trailing dots for hostname types
     * (CNAME, NS, MX, PTR, SRV, etc.), but the DB stores them without.
     */
    private function stripTrailingDotFromContent(string $type, string $content): string
    {
        $hostnameTypes = ['CNAME', 'NS', 'MX', 'PTR', 'SRV', 'AFSDB', 'DNAME', 'ALIAS'];
        if (in_array($type, $hostnameTypes, true)) {
            return rtrim($content, '.');
        }
        return $content;
    }

    /**
     * Resolve numeric record IDs from the database for API-sourced records.
     *
     * Matches records by (domain_id, name, type, content, prio) and assigns
     * the DB ID. Records without a DB match keep id=0.
     *
     * @param array &$records Records array to update in-place
     * @param int $domainId Domain ID
     */
    private function resolveRecordIds(array &$records, int $domainId): void
    {
        if (empty($records)) {
            return;
        }

        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $stmt = $this->db->prepare(
            "SELECT id, name, type, content, prio FROM $recordsTable
             WHERE domain_id = :did AND type IS NOT NULL AND type != ''"
        );
        $stmt->bindValue(':did', $domainId, PDO::PARAM_INT);
        $stmt->execute();

        // Build lookup map: "name|type|content|prio" => [id1, id2, ...]
        // Using an array of IDs handles duplicate records (same name, type,
        // content, prio) by assigning each a unique DB ID via FIFO consumption.
        $idMap = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['name'] . '|' . $row['type'] . '|' . $row['content'] . '|' . (int)$row['prio'];
            $idMap[$key][] = (int)$row['id'];
        }

        foreach ($records as &$record) {
            $key = $record['name'] . '|' . $record['type'] . '|' . $record['content'] . '|' . $record['prio'];
            if (!empty($idMap[$key])) {
                $record['id'] = array_shift($idMap[$key]);
            }
        }
        unset($record);
    }

    /**
     * Get all records for a given RRset (domain_id + name + type) from the database.
     */
    private function getRecordsFromDb(int $domainId, string $name, string $type): array
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT id, domain_id, name, type, content, ttl, prio, disabled FROM $recordsTable WHERE domain_id = :did AND name = :name AND type = :type AND type IS NOT NULL");
        $stmt->bindValue(':did', $domainId, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
