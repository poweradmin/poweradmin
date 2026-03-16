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
use Poweradmin\Domain\ValueObject\RecordIdentifier;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PowerDNS REST API-based DNS backend provider.
 *
 * Performs all DNS operations through the PowerDNS REST API.
 * Zone metadata is stored locally in the Poweradmin zones table.
 * Records are identified by encoded composite keys (no PowerDNS DB access needed).
 */
class ApiDnsBackendProvider implements DnsBackendProvider
{
    private PowerdnsApiClient $client;
    private PDO $db;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;

    public function __construct(PowerdnsApiClient $client, PDO $db, ConfigurationInterface $config, ?LoggerInterface $logger = null)
    {
        $this->client = $client;
        $this->db = $db;
        $this->config = $config;
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

        // Store zone_name in local zones table for API-mode identification.
        // The caller (DomainManager) will insert into zones table with the returned ID
        // as domain_id. We look up if there's already an entry with this zone_name.
        $stmt = $this->db->prepare("SELECT id FROM zones WHERE zone_name = :name");
        $stmt->execute([':name' => $domain]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            // Update existing entry
            $stmt = $this->db->prepare("UPDATE zones SET zone_type = :type, zone_master = :master WHERE id = :id");
            $stmt->bindValue(':type', strtoupper($type));
            $stmt->bindValue(':master', $slaveMaster);
            $stmt->bindValue(':id', (int)$existingId, PDO::PARAM_INT);
            $stmt->execute();
            return (int)$existingId;
        }

        // Insert a placeholder entry. The caller will update it with owner/template info.
        $stmt = $this->db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id, zone_name, zone_type, zone_master) VALUES (0, NULL, 0, :name, :type, :master)");
        $stmt->bindValue(':name', $domain);
        $stmt->bindValue(':type', strtoupper($type));
        $stmt->bindValue(':master', $slaveMaster);
        $stmt->execute();

        $zonesId = (int)$this->db->lastInsertId();

        // Set domain_id = zones.id so existing code that uses domain_id works
        $stmt = $this->db->prepare("UPDATE zones SET domain_id = :did WHERE id = :id");
        $stmt->bindValue(':did', $zonesId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $zonesId, PDO::PARAM_INT);
        $stmt->execute();

        return $zonesId;
    }

    public function deleteZone(int $domainId, string $zoneName): bool
    {
        $apiName = self::ensureTrailingDot($zoneName);
        $zone = new Zone($apiName);

        return $this->client->deleteZone($zone);
    }

    public function updateZoneType(int $domainId, string $type): bool
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return false;
        }

        $apiName = self::ensureTrailingDot($zoneName);
        $data = ['kind' => $type];

        if ($type !== 'SLAVE') {
            $data['masters'] = [];
        }

        $result = $this->client->updateZoneProperties($apiName, $data);

        if ($result) {
            $stmt = $this->db->prepare("UPDATE zones SET zone_type = :type WHERE id = :id");
            $stmt->bindValue(':type', strtoupper($type));
            $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $result;
    }

    public function updateZoneMaster(int $domainId, string $masterIp): bool
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return false;
        }

        $apiName = self::ensureTrailingDot($zoneName);
        $result = $this->client->updateZoneProperties($apiName, ['masters' => [$masterIp]]);

        if ($result) {
            $stmt = $this->db->prepare("UPDATE zones SET zone_master = :master WHERE id = :id");
            $stmt->bindValue(':master', $masterIp);
            $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $result;
    }

    public function updateZoneAccount(int $domainId, string $account): bool
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
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
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return false;
        }

        $apiZoneName = self::ensureTrailingDot($zoneName);
        $apiRecordName = self::ensureTrailingDot($name);

        // Read existing RRset from the API to avoid clobbering
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

    public function addRecordGetId(int $domainId, string $name, string $type, string $content, int $ttl, int $prio): int|string|null
    {
        if (!$this->addRecord($domainId, $name, $type, $content, $ttl, $prio)) {
            return null;
        }

        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return null;
        }

        return RecordIdentifier::encode($zoneName, $name, $type, $content, $prio);
    }

    public function createRecordAtomic(int $domainId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled = 0): int|string|null
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return null;
        }

        $apiZoneName = self::ensureTrailingDot($zoneName);
        $apiRecordName = self::ensureTrailingDot($name);

        // Read existing RRset from the API
        $rrsetData = $this->getRRsetFromApi($apiZoneName, $apiRecordName, $type);
        if ($rrsetData === null) {
            $this->logger->error("Failed to fetch current RRset for '{name} {type}' from API", [
                'name' => $apiRecordName, 'type' => $type,
            ]);
            return null;
        }

        if ($type === 'SOA') {
            $records = [
                [
                    'content' => $this->formatRecordContent($type, $content, $prio),
                    'disabled' => (bool)$disabled,
                ],
            ];
        } else {
            $records = $rrsetData['records'];
            $records[] = [
                'content' => $this->formatRecordContent($type, $content, $prio),
                'disabled' => (bool)$disabled,
            ];
        }

        $rrset = [
            'name' => $apiRecordName,
            'type' => $type,
            'ttl' => $ttl,
            'changetype' => 'REPLACE',
            'records' => $records,
        ];

        if (!$this->client->patchZoneRRsets($apiZoneName, [$rrset])) {
            return null;
        }

        return RecordIdentifier::encode($zoneName, $name, $type, $content, $prio);
    }

    public function editRecord(int|string $recordId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled): bool
    {
        // Decode the encoded record ID to get the old record identity
        if (!RecordIdentifier::isEncoded($recordId)) {
            $this->logger->error("editRecord called with non-encoded ID in API mode: {id}", ['id' => $recordId]);
            return false;
        }

        $old = RecordIdentifier::decode((string)$recordId);
        $zoneName = $old['zone_name'];
        $apiZoneName = self::ensureTrailingDot($zoneName);
        $oldApiContent = $this->formatRecordContent($old['type'], $old['content'], $old['prio']);
        $rrsets = [];

        // If name or type changed, we need to remove from old RRset and add to new RRset
        if ($old['name'] !== $name || $old['type'] !== $type) {
            // Remove from old RRset
            $oldRRsetData = $this->getRRsetFromApi($apiZoneName, self::ensureTrailingDot($old['name']), $old['type']);
            if ($oldRRsetData === null) {
                $this->logger->error("Failed to fetch old RRset for '{name} {type}' from API", [
                    'name' => $old['name'], 'type' => $old['type'],
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
                    'name' => self::ensureTrailingDot($old['name']),
                    'type' => $old['type'],
                    'changetype' => 'DELETE',
                ];
            } else {
                $rrsets[] = [
                    'name' => self::ensureTrailingDot($old['name']),
                    'type' => $old['type'],
                    'ttl' => $oldRRsetData['ttl'],
                    'changetype' => 'REPLACE',
                    'records' => $remainingRecords,
                ];
            }

            // Add to new RRset
            $newRRsetData = $this->getRRsetFromApi($apiZoneName, self::ensureTrailingDot($name), $type);
            if ($newRRsetData === null) {
                $this->logger->error("Failed to fetch new RRset for '{name} {type}' from API", [
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
                $this->logger->error("Failed to fetch RRset for '{name} {type}' from API", [
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
                $this->logger->error("editRecord: encoded record content not found in RRset for '{name} {type}'", [
                    'name' => $name, 'type' => $type,
                ]);
                return false;
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

    public function deleteRecord(int|string $recordId): bool
    {
        if (!RecordIdentifier::isEncoded($recordId)) {
            $this->logger->error("deleteRecord called with non-encoded ID in API mode: {id}", ['id' => $recordId]);
            return false;
        }

        $record = RecordIdentifier::decode((string)$recordId);
        $zoneName = $record['zone_name'];
        $apiZoneName = self::ensureTrailingDot($zoneName);
        $apiRecordName = self::ensureTrailingDot($record['name']);

        // Read sibling records from the API
        $rrsetData = $this->getRRsetFromApi($apiZoneName, $apiRecordName, $record['type']);
        if ($rrsetData === null) {
            $this->logger->error("Failed to fetch current RRset for '{name} {type}' from API", [
                'name' => $record['name'], 'type' => $record['type'],
            ]);
            return false;
        }

        // Remove the target record by matching its content
        $deleteContent = $this->formatRecordContent($record['type'], $record['content'], $record['prio']);
        $remainingRecords = [];
        $found = false;
        foreach ($rrsetData['records'] as $r) {
            if (!$found && self::contentMatchesApi($r['content'], $deleteContent)) {
                $found = true;
                continue;
            }
            $remainingRecords[] = $r;
        }

        if (!$found) {
            $this->logger->error("deleteRecord: encoded record content not found in RRset for '{name} {type}'", [
                'name' => $record['name'], 'type' => $record['type'],
            ]);
            return false;
        }

        if (empty($remainingRecords)) {
            $rrset = [
                'name' => $apiRecordName,
                'type' => $record['type'],
                'changetype' => 'DELETE',
            ];
        } else {
            $rrset = [
                'name' => $apiRecordName,
                'type' => $record['type'],
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
        $zoneName = $this->getZoneNameByLocalId($domainId);
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
        return $this->getZoneNameByLocalId($domainId);
    }

    public function getZoneIdByName(string $zoneName): ?int
    {
        if (empty($zoneName)) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT id FROM zones WHERE zone_name = :name");
        $stmt->execute([':name' => $zoneName]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    public function getZoneTypeById(int $domainId): string
    {
        // First check local cache
        $stmt = $this->db->prepare("SELECT zone_type FROM zones WHERE id = :id OR domain_id = :id2");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->bindValue(':id2', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $type = $stmt->fetchColumn();

        if ($type !== false && $type !== null) {
            return $type;
        }

        // Fallback to API
        $zone = $this->getZoneById($domainId);
        return $zone ? ($zone['type'] ?: 'NATIVE') : 'NATIVE';
    }

    public function getZoneMasterById(int $domainId): ?string
    {
        // First check local cache
        $stmt = $this->db->prepare("SELECT zone_master, zone_type FROM zones WHERE id = :id OR domain_id = :id2");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->bindValue(':id2', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false && strtoupper($row['zone_type'] ?? '') === 'SLAVE') {
            return $row['zone_master'] ?: null;
        }

        // Fallback to API
        $zone = $this->getZoneById($domainId);
        if ($zone === null || strtoupper($zone['type']) !== 'SLAVE') {
            return null;
        }
        return $zone['master'] ?: null;
    }

    // ---------------------------------------------------------------
    // Record read methods
    // ---------------------------------------------------------------

    public function getRecordById(int|string $recordId): ?array
    {
        if (!RecordIdentifier::isEncoded($recordId)) {
            return null;
        }

        $decoded = RecordIdentifier::decode((string)$recordId);
        $zoneName = $decoded['zone_name'];
        $zoneId = $this->getZoneIdByName($zoneName);

        // Fetch record from API to get current TTL and disabled state
        $apiZoneName = self::ensureTrailingDot($zoneName);
        $apiRecordName = self::ensureTrailingDot($decoded['name']);
        $rrsetData = $this->getRRsetFromApi($apiZoneName, $apiRecordName, $decoded['type']);

        $disabled = 0;
        $ttl = 3600;
        if ($rrsetData !== null) {
            $ttl = $rrsetData['ttl'];
            $targetContent = $this->formatRecordContent($decoded['type'], $decoded['content'], $decoded['prio']);
            foreach ($rrsetData['records'] as $r) {
                if (self::contentMatchesApi($r['content'], $targetContent)) {
                    $disabled = ($r['disabled'] ?? false) ? 1 : 0;
                    break;
                }
            }
        }

        return [
            'id' => $recordId,
            'domain_id' => $zoneId ?? 0,
            'name' => $decoded['name'],
            'type' => $decoded['type'],
            'content' => $decoded['content'],
            'ttl' => $ttl,
            'prio' => $decoded['prio'],
            'disabled' => $disabled,
        ];
    }

    public function getZoneIdFromRecordId(int|string $recordId): int
    {
        if (!RecordIdentifier::isEncoded($recordId)) {
            return 0;
        }

        $decoded = RecordIdentifier::decode((string)$recordId);
        $zoneId = $this->getZoneIdByName($decoded['zone_name']);

        return $zoneId ?? 0;
    }

    public function countZoneRecords(int $domainId): int
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return 0;
        }
        $records = $this->getZoneRecords($domainId, $zoneName);
        return count($records);
    }

    public function recordExists(int $domainId, string $name, string $type, string $content): bool
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
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
        $zoneName = $this->getZoneNameByLocalId($domainId);
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
        $zoneName = $this->getZoneNameByLocalId($domainId);
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
                'type' => '',
                'master' => '',
                'dnssec' => $zone->isSecured(),
            ];
        }

        // Enrich with local zone data (id, type, master) from zones table
        if (!empty($zones)) {
            $stmt = $this->db->query("SELECT id, domain_id, zone_name, zone_type, zone_master FROM zones WHERE zone_name IS NOT NULL");
            $localZones = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $localZones[$row['zone_name']] = $row;
            }

            foreach ($zones as &$zone) {
                if (isset($localZones[$zone['name']])) {
                    $local = $localZones[$zone['name']];
                    $zone['id'] = (int)($local['domain_id'] ?: $local['id']);
                    $zone['type'] = strtoupper($local['zone_type'] ?? '');
                    $zone['master'] = $local['zone_master'] ?? '';
                }
            }
            unset($zone);

            // For zones without local data, fetch type from API
            foreach ($zones as &$zone) {
                if (empty($zone['type']) && $zone['id'] === 0) {
                    $apiName = self::ensureTrailingDot($zone['name']);
                    $zoneData = $this->client->getZone($apiName);
                    if ($zoneData !== null) {
                        $zone['type'] = strtoupper($zoneData['kind'] ?? '');
                        $zone['master'] = implode(',', $zoneData['masters'] ?? []);
                    }
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

        // Get local zone ID
        $stmt = $this->db->prepare("SELECT id, domain_id FROM zones WHERE zone_name = :name");
        $stmt->execute([':name' => $zoneName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = $row !== false ? (int)($row['domain_id'] ?: $row['id']) : 0;

        return [
            'id' => $id,
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
                    'id' => RecordIdentifier::encode($zoneName, $name, $type, $content, $prio),
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

        return $records;
    }

    // ---------------------------------------------------------------
    // Search operations
    // ---------------------------------------------------------------

    public function searchDnsData(string $query, string $objectType = 'all', int $max = 100): array
    {
        $wildcardQuery = '*' . $query . '*';
        $apiResults = $this->client->searchData($wildcardQuery, $objectType, $max);

        $zones = [];
        $records = [];

        // Build local zone lookup
        $stmt = $this->db->query("SELECT id, domain_id, zone_name FROM zones WHERE zone_name IS NOT NULL");
        $localZones = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $localZones[$row['zone_name']] = $row;
        }

        foreach ($apiResults as $result) {
            $objType = $result['object_type'] ?? '';
            $name = rtrim($result['name'] ?? '', '.');

            if ($objType === 'zone') {
                $zoneId = 0;
                if (isset($localZones[$name])) {
                    $zoneId = (int)($localZones[$name]['domain_id'] ?: $localZones[$name]['id']);
                }
                $zones[] = [
                    'id' => $zoneId,
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

                $domainId = 0;
                if (isset($localZones[$zoneName])) {
                    $domainId = (int)($localZones[$zoneName]['domain_id'] ?: $localZones[$zoneName]['id']);
                }

                $records[] = [
                    'id' => RecordIdentifier::encode($zoneName, $name, $type, $content, $prio),
                    'domain_id' => $domainId,
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

        return ['zones' => $zones, 'records' => $records];
    }

    // ---------------------------------------------------------------
    // SOA operations
    // ---------------------------------------------------------------

    public function updateSOASerial(int $domainId): bool
    {
        // In API mode, PowerDNS handles SOA serial increments automatically
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
        $sameEntry = ($oldMasterIp === $newMasterIp && $oldNsName === $newNsName);

        if ($sameEntry) {
            $oldAccount = $this->getAutoprimaryAccount($oldMasterIp, $oldNsName);

            if (!$this->client->deleteAutoprimary($oldMasterIp, $oldNsName)) {
                return false;
            }
            if (!$this->client->addAutoprimary($newMasterIp, $newNsName, $account)) {
                $this->client->addAutoprimary($oldMasterIp, $oldNsName, $oldAccount);
                return false;
            }
            return true;
        }

        if (!$this->client->addAutoprimary($newMasterIp, $newNsName, $account)) {
            return false;
        }

        if (!$this->client->deleteAutoprimary($oldMasterIp, $oldNsName)) {
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
     * Get zone name from the local zones table by Poweradmin zone ID.
     * Looks up by both zones.id and zones.domain_id for compatibility.
     */
    private function getZoneNameByLocalId(int $id): ?string
    {
        $stmt = $this->db->prepare("SELECT zone_name FROM zones WHERE id = :id OR domain_id = :did LIMIT 1");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':did', $id, PDO::PARAM_INT);
        $stmt->execute();
        $name = $stmt->fetchColumn();

        return $name !== false && $name !== null ? $name : null;
    }

    private static function ensureTrailingDot(string $name): string
    {
        return str_ends_with($name, '.') ? $name : $name . '.';
    }

    private static function contentMatchesApi(string $apiContent, string $dbFormattedContent): bool
    {
        return rtrim($apiContent, '.') === rtrim($dbFormattedContent, '.');
    }

    private function formatRecordContent(string $type, string $content, int $prio): string
    {
        if ($type === 'MX' || $type === 'SRV') {
            return $prio . ' ' . $content;
        }

        if ($type === 'SOA') {
            $parts = explode(' ', $content);
            if (count($parts) >= 7) {
                $parts[0] = self::ensureTrailingDot($parts[0]);
                $parts[1] = self::ensureTrailingDot($parts[1]);
                return implode(' ', $parts);
            }
        }

        return $content;
    }

    private function stripTrailingDotFromContent(string $type, string $content): string
    {
        $hostnameTypes = ['CNAME', 'NS', 'MX', 'PTR', 'SRV', 'AFSDB', 'DNAME', 'ALIAS'];
        if (in_array($type, $hostnameTypes, true)) {
            return rtrim($content, '.');
        }
        return $content;
    }

    /**
     * Get the current records for an RRset directly from the PowerDNS API.
     *
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
}
