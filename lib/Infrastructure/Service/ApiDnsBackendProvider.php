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

use Poweradmin\Infrastructure\Database\CanonicalZoneIdAllocator;
use Poweradmin\Infrastructure\Database\DeadlockRetry;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Database\SharedZoneIds;
use Poweradmin\Domain\Port\ReportsWriteRejection;
use Poweradmin\Domain\Port\TransactionInterface;
use PDO;
use Poweradmin\Domain\Port\ApiStatusRecorderInterface;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\ValueObject\RecordIdentifier;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Database\CanonicalZoneSql;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PowerDNS REST API-based DNS backend provider.
 *
 * Performs all DNS operations through the PowerDNS REST API.
 * Zone metadata is stored locally in the Poweradmin zones table.
 * Records are identified by encoded composite keys (no PowerDNS DB access needed).
 */
final class ApiDnsBackendProvider implements DnsBackendProviderInterface, ReportsWriteRejection
{
    /** Exact record lookups ask for this many matches, the limit zone search uses too */
    private const EXACT_SEARCH_LIMIT = 10000;

    private PowerdnsApiClient $client;
    private PDO $db;
    private TransactionInterface $transaction;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private ApiStatusRecorderInterface $apiStatus;

    /** @var array<string, int>|null Zone name to local id, resolved once per request. */
    private ?array $localZoneIds = null;

    private ?string $lastWriteRejection = null;
    private ?int $lastWriteRejectionStatus = null;

    public function __construct(PowerdnsApiClient $client, PDO $db, ConfigurationInterface $config, ApiStatusRecorderInterface $apiStatus, ?LoggerInterface $logger = null, ?TransactionInterface $transaction = null)
    {
        $this->apiStatus = $apiStatus;
        $this->transaction = $transaction ?? new PdoTransaction($db);
        $this->client = $client;
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * The client carries per-request read caches, so anything needing raw API
     * access shares this one rather than building a second.
     */
    public function getApiClient(): PowerdnsApiClient
    {
        return $this->client;
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

        if (ZoneType::replicatesFromPrimary($type) && $slaveMaster !== '') {
            $zoneData['masters'] = self::parseMasters($slaveMaster);
        }

        $result = $this->client->createZoneWithData($zoneData);
        if ($result === null) {
            return false;
        }

        // The zone now exists in PowerDNS; if the local row cannot be written it must not stay
        // behind, or the user sees a failure and a retry then collides with the orphan.
        try {
            $zonesId = $this->storeLocalZoneRow($domain, $type, $slaveMaster);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Zone {zone} was created in PowerDNS but its local row could not be stored ({class}, SQLSTATE {state}): {error}',
                [
                    'zone' => $domain,
                    'class' => get_class($e),
                    'state' => $e instanceof \PDOException ? (string)($e->errorInfo[0] ?? $e->getCode()) : '-',
                    'error' => $e->getMessage(),
                ]
            );
            $this->removeOrphanedZone($apiName);
            throw $e;
        }

        // The lazy zone-id cache predates this row, so leave it to be rebuilt on next read.
        $this->localZoneIds = null;

        return $zonesId;
    }

    /**
     * Records the zone in the local zones table and returns its canonical id. Retries the
     * transaction when the database rolls it back as a deadlock victim.
     */
    private function storeLocalZoneRow(string $domain, string $type, string $slaveMaster): int
    {
        // The caller (DomainManager) will fill in the owner and template of this row.
        // The insert and its domain_id backfill must land together. Committed apart, an
        // interrupted request strands the row at a domain_id no canonical read resolves.
        $ownsTransaction = !$this->transaction->inTransaction();
        $attempt = function () use ($domain, $type, $slaveMaster, $ownsTransaction): int {
            if ($ownsTransaction) {
                $this->transaction->begin();
            }

            try {
                // Locks the backend marker row first, so concurrent creators queue instead of deadlocking
                $allocator = new CanonicalZoneIdAllocator($this->db);

                // Read under the marker lock, so a zone adopted by a concurrent sync is seen, not collided with
                $stmt = $this->db->prepare("SELECT id, domain_id FROM zones WHERE zone_name = :name");
                $stmt->execute([':name' => $domain]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if (is_array($existing) && $this->dropStaleSharedRow((int)$existing['id'], (int)($existing['domain_id'] ?: $existing['id']))) {
                    $existing = false;
                }

                if (is_array($existing)) {
                    $stmt = $this->db->prepare("UPDATE zones SET zone_type = :type, zone_master = :master WHERE id = :id");
                    $stmt->bindValue(':type', strtoupper($type));
                    $stmt->bindValue(':master', $slaveMaster);
                    $stmt->bindValue(':id', (int)$existing['id'], PDO::PARAM_INT);
                    $stmt->execute();
                    if ($ownsTransaction) {
                        $this->transaction->commit();
                    }

                    // Callers address the zone by its canonical id, which differs from the row id on migrated rows
                    return (int)($existing['domain_id'] ?: $existing['id']);
                }

                // Insert a placeholder entry. The caller will update it with owner/template info.
                $stmt = $this->db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id, zone_name, zone_type, zone_master) VALUES (NULL, NULL, 0, :name, :type, :master)");
                $stmt->bindValue(':name', $domain);
                $stmt->bindValue(':type', strtoupper($type));
                $stmt->bindValue(':master', $slaveMaster);
                $stmt->execute();

                // The canonical id: the row id, unless another zone or grant already uses that number
                $zonesId = $allocator->settle((int)$this->db->lastInsertId('zones_id_seq'));

                if ($ownsTransaction) {
                    $this->transaction->commit();
                }

                return $zonesId;
            } catch (\Throwable $e) {
                if ($ownsTransaction) {
                    $this->rollBackIfOpen();
                }
                throw $e;
            }
        };

        // A caller's transaction cannot be replayed from here, so only our own is retried
        return $ownsTransaction ? DeadlockRetry::run($attempt) : $attempt();
    }

    /**
     * Delete the row a zone of this name already has when another zone shares its canonical
     * id. PowerDNS has just accepted the zone, so that row is left from a zone deleted there,
     * and the caller's owner update by canonical id would also rewrite the other zone.
     */
    private function dropStaleSharedRow(int $rowId, int $zoneId): bool
    {
        if (!SharedZoneIds::isShared($this->db, $zoneId)) {
            return false;
        }

        SharedZoneIds::unscopeApiKeysFromOpenedRow($this->db, $zoneId, $rowId, $this->logger);
        // Grants keyed by a shared id cannot be attributed, so they go too rather than
        // falling to the surviving zone, as in zone delete
        $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :zone_id");
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $stmt = $this->db->prepare("DELETE FROM zones WHERE zone_name IS NULL AND domain_id = :zone_id");
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $stmt = $this->db->prepare("DELETE FROM zones WHERE id = :id");
        $stmt->bindValue(':id', $rowId, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    private function removeOrphanedZone(string $apiName): void
    {
        try {
            $removed = $this->client->deleteZone(new Zone($apiName));
        } catch (\Throwable $e) {
            $removed = false;
        }
        if (!$removed) {
            $this->logger->error('Zone {zone} could not be removed from PowerDNS after its local row failed; delete it there by hand', ['zone' => $apiName]);
        }
    }

    /**
     * Some drivers roll back on their own when a statement fails, so the transaction may
     * already be gone by the time we get here.
     */
    private function rollBackIfOpen(): void
    {
        if ($this->transaction->inTransaction()) {
            $this->transaction->rollBack();
        }
    }

    public function deleteZone(int $domainId, string $zoneName): bool
    {
        // Without a name there is nothing to address; "." would target the root zone
        if ($zoneName === '') {
            return false;
        }

        $apiName = self::ensureTrailingDot($zoneName);
        $zone = new Zone($apiName);

        return $this->client->deleteZone($zone);
    }

    public function updateZoneType(int $domainId, string $type): bool
    {
        $zone = $this->resolveCanonicalZoneRow($domainId);
        if ($zone === null) {
            return false;
        }

        $apiName = self::ensureTrailingDot($zone['zone_name']);
        $data = ['kind' => $type];
        $clearsMasters = !ZoneType::replicatesFromPrimary($type);

        if ($clearsMasters) {
            $data['masters'] = [];
        }

        $result = $this->client->updateZoneProperties($apiName, $data);

        if ($result) {
            // Keep the cached master in step with the masters PowerDNS was just told to drop
            $masterClause = $clearsMasters ? ", zone_master = ''" : '';
            $stmt = $this->db->prepare("UPDATE zones SET zone_type = :type{$masterClause} WHERE id = :id");
            $stmt->bindValue(':type', strtoupper($type));
            $stmt->bindValue(':id', (int)$zone['id'], PDO::PARAM_INT);
            $stmt->execute();
        }

        return $result;
    }

    public function updateZoneMaster(int $domainId, string $masterIp): bool
    {
        $zone = $this->resolveCanonicalZoneRow($domainId);
        if ($zone === null) {
            return false;
        }

        $apiName = self::ensureTrailingDot($zone['zone_name']);
        $result = $this->client->updateZoneProperties($apiName, ['masters' => self::parseMasters($masterIp)]);

        if ($result) {
            $stmt = $this->db->prepare("UPDATE zones SET zone_master = :master WHERE id = :id");
            $stmt->bindValue(':master', $masterIp);
            $stmt->bindValue(':id', (int)$zone['id'], PDO::PARAM_INT);
            $stmt->execute();
        }

        return $result;
    }

    public function retrieveZone(int $domainId): bool
    {
        $zone = $this->resolveCanonicalZoneRow($domainId);

        if ($zone === null || strtoupper($zone['zone_type'] ?? '') !== 'SLAVE') {
            return false;
        }

        return $this->client->retrieveZone(self::ensureTrailingDot($zone['zone_name']));
    }

    public function notifyZone(int $domainId): bool
    {
        $zone = $this->resolveCanonicalZoneRow($domainId);
        // A row the zone sync has not filled in yet has no cached type; ask PowerDNS then
        $kind = $zone === null ? null : $this->resolveKindAndMaster($domainId);

        if ($zone === null || $kind === null || !ZoneType::notifies($kind['type'])) {
            return false;
        }

        return $this->client->notifyZone(self::ensureTrailingDot($zone['zone_name']));
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

    public function getCatalogMembers(string $catalogName): array
    {
        return array_map(
            fn(array $zone): array => ['id' => $zone['id'], 'name' => $zone['name'], 'kind' => $zone['kind']],
            $this->zonesMatching(fn(array $zone): bool => $zone['catalog'] === $catalogName, null)
        );
    }

    public function getZonesByKind(string $kind): array
    {
        $wanted = strtoupper($kind);

        return array_map(
            fn(array $zone): array => ['id' => $zone['id'], 'name' => $zone['name'], 'catalog' => $zone['catalog']],
            $this->zonesMatching(fn(array $zone): bool => $zone['kind'] === $wanted, $wanted)
        );
    }

    /**
     * Zones from the bulk list that satisfy $match, name-sorted.
     *
     * @param callable(array{id: int, name: string, kind: string, catalog: string}): bool $match
     * @param string|null $kind Only zones of this kind, checked before any per-zone catalog read
     * @return array<int, array{id: int, name: string, kind: string, catalog: string}>
     */
    private function zonesMatching(callable $match, ?string $kind): array
    {
        // A producer or consumer lookup reads no catalog, so it skips the server version probe too
        $readsCatalogPerZone = !in_array($kind, [ZoneType::PRODUCER, ZoneType::CONSUMER], true)
            && $this->client->zoneListOmitsCatalog();
        $zones = [];
        foreach ($this->readZoneKinds() as $apiName => $entry) {
            if ($kind !== null && $entry['kind'] !== $kind) {
                continue;
            }
            $name = rtrim($apiName, '.');
            $zone = [
                'id' => $this->localIdForZoneName($name),
                'name' => $name,
                'kind' => $entry['kind'],
                // Producers and consumers cannot be members, so their catalog is never read
                'catalog' => $readsCatalogPerZone && !in_array($entry['kind'], [ZoneType::PRODUCER, ZoneType::CONSUMER], true)
                    ? $this->client->getZoneCatalogName($apiName)
                    : $entry['catalog'],
            ];

            if ($match($zone)) {
                $zones[] = $zone;
            }
        }

        usort($zones, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $zones;
    }

    public function getZoneCatalog(int $domainId): string
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return '';
        }

        // Served from the zone body already held for the record listing on the
        // edit page, so this costs nothing there.
        $zoneData = $this->client->getZone(self::ensureTrailingDot($zoneName), false);

        return PowerdnsApiClient::canonicalZoneName((string)($zoneData['catalog'] ?? ''));
    }

    public function updateZoneCatalog(int $domainId, string $catalogName): bool
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return false;
        }

        // PowerDNS only reads this field when it is a JSON string; null is silently
        // ignored and leaves the zone in its current catalog.
        $value = $catalogName === '' ? '' : self::ensureTrailingDot($catalogName);

        return $this->client->updateZoneProperties(self::ensureTrailingDot($zoneName), ['catalog' => $value]);
    }

    /**
     * @return array<string, array{kind: string, masters: array<int, string>, catalog: string}>
     */
    private function readZoneKinds(): array
    {
        try {
            return $this->client->getAllZoneKinds(false);
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get zone kinds from API: {error}', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function localIdForZoneName(string $zoneName): int
    {
        if ($this->localZoneIds === null) {
            $this->localZoneIds = [];
            $stmt = $this->db->query("SELECT id, domain_id, zone_name FROM zones WHERE zone_name IS NOT NULL");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->localZoneIds[$row['zone_name']] = (int)($row['domain_id'] ?: $row['id']);
            }
        }

        return $this->localZoneIds[$zoneName] ?? 0;
    }

    // ---------------------------------------------------------------
    // Record operations
    // ---------------------------------------------------------------

    public function rewriteSoaContent(int $zoneId, callable $change): bool
    {
        $current = $this->getSOARecord($zoneId);
        if ($current === '') {
            return false;
        }
        $content = $change($current);

        return $content === null || $content === $current || $this->replaceSoaContent($zoneId, $content);
    }

    public function replaceSoaContent(int $zoneId, string $content): bool
    {
        $zoneName = $this->getZoneNameByLocalId($zoneId);
        if ($zoneName === null) {
            return false;
        }
        $apexName = DnsHelper::zoneApexName($zoneName);
        // A serial bump rewrites the SOA, so it keeps the TTL the zone already has
        $current = $this->getRRsetFromApi(self::ensureTrailingDot($zoneName), self::ensureTrailingDot($apexName), 'SOA');
        $soaTtl = $current !== null && $current['records'] !== []
            ? (int)$current['ttl']
            : (int)$this->config->get('dns', 'ttl', 86400);

        // The zone id stays the variant; the SOA record sits at its apex name
        return $this->addRecord($zoneId, $apexName, 'SOA', $content, $soaTtl, 0);
    }

    public function addRecord(int $domainId, string $name, string $type, string $content, int $ttl, int $prio): bool
    {
        return $this->appendRecord($domainId, $name, $type, $content, $ttl, $prio, 0, null) !== null;
    }

    public function addRecordGetId(int $domainId, string $name, string $type, string $content, int $ttl, int $prio, ?array $comment = null): ?string
    {
        return $this->appendRecord($domainId, $name, $type, $content, $ttl, $prio, 0, $comment);
    }

    public function createRecordAtomic(int $domainId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled = 0, ?array $comment = null): ?string
    {
        return $this->appendRecord($domainId, $name, $type, $content, $ttl, $prio, $disabled, $comment);
    }

    /**
     * REPLACE the RRset with the existing records plus the new one and return its encoded ID.
     */
    private function appendRecord(int $domainId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled, ?array $comment): ?string
    {
        $this->lastWriteRejection = null;
        $this->lastWriteRejectionStatus = null;
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            $this->logger->error('Cannot create record: zone id {id} has no local zones row', ['id' => $domainId]);
            return null;
        }

        $apiZoneName = self::ensureTrailingDot($zoneName);
        $apiRecordName = self::ensureTrailingDot($name);

        // Read existing RRset from the API to avoid clobbering
        $rrsetData = $this->getRRsetFromApi($apiZoneName, $apiRecordName, $type);
        if ($rrsetData === null) {
            $this->logger->error("Failed to fetch current RRset for '{name} {type}' from API - aborting to prevent data loss", [
                'name' => $apiRecordName, 'type' => $type,
            ]);
            return null;
        }

        $newRecord = [
            'content' => $this->formatRecordContent($type, $content, $prio),
            'disabled' => (bool)$disabled,
        ];
        // SOA is a singleton type - replace the existing record instead of appending
        $records = $type === 'SOA' ? [$newRecord] : [...$rrsetData['records'], $newRecord];

        $rrset = [
            'name' => $apiRecordName,
            'type' => $type,
            'ttl' => $ttl,
            'changetype' => 'REPLACE',
            'records' => $records,
        ];
        // Same PATCH as the record: a separate comments-only PATCH would make
        // PowerDNS bump the SOA serial twice under SOA-EDIT-API
        if ($comment !== null) {
            $rrset['comments'] = self::rrsetComments($comment);
        }

        if (!$this->client->patchZoneRRsets($apiZoneName, [$rrset])) {
            $this->lastWriteRejection = $this->client->getLastWriteRejection();
            $this->lastWriteRejectionStatus = $this->client->getLastWriteRejectionStatus();
            return null;
        }

        return RecordIdentifier::encode($zoneName, $name, $type, $content, $prio);
    }

    public function editRecord(int|string $recordId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled, ?array $comment = null): bool
    {
        $this->lastWriteRejection = null;
        $this->lastWriteRejectionStatus = null;
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

            if (!$found) {
                $this->logger->error("editRecord: encoded record content not found in old RRset for '{name} {type}'", [
                    'name' => $old['name'], 'type' => $old['type'],
                ]);
                return false;
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

            $targetRecords = $newRecords;
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

            $targetRecords = $records;
        }

        $target = [
            'name' => self::ensureTrailingDot($name),
            'type' => $type,
            'ttl' => $ttl,
            'changetype' => 'REPLACE',
            'records' => $targetRecords,
        ];
        // Carry the comment in the same PATCH: a second comments-only PATCH would
        // make PowerDNS bump the SOA serial twice under SOA-EDIT-API
        if ($comment !== null) {
            $target['comments'] = self::rrsetComments($comment);
        }
        $rrsets[] = $target;

        if (!$this->client->patchZoneRRsets($apiZoneName, $rrsets)) {
            $this->lastWriteRejection = $this->client->getLastWriteRejection();
            $this->lastWriteRejectionStatus = $this->client->getLastWriteRejectionStatus();
            return false;
        }

        return true;
    }

    public function lastWriteRejection(): ?string
    {
        return $this->lastWriteRejection;
    }

    public function lastWriteRejectionStatus(): ?int
    {
        return $this->lastWriteRejectionStatus;
    }

    /**
     * PowerDNS comments list for a REPLACE; empty content clears the RRset comment.
     */
    private static function rrsetComments(array $comment): array
    {
        if ($comment['content'] === '') {
            return [];
        }

        return [[
            'content' => $comment['content'],
            'account' => $comment['account'],
            'modified_at' => time(),
        ]];
    }

    public function deleteRecord(int|string $recordId): bool
    {
        $this->lastWriteRejection = null;
        $this->lastWriteRejectionStatus = null;
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

        if (!$this->client->patchZoneRRsets($apiZoneName, [$rrset])) {
            $this->lastWriteRejection = $this->client->getLastWriteRejection();
            $this->lastWriteRejectionStatus = $this->client->getLastWriteRejectionStatus();
            return false;
        }

        return true;
    }

    // ---------------------------------------------------------------
    // Zone read methods
    // ---------------------------------------------------------------

    public function zoneExists(string $zoneName): bool
    {
        $apiName = self::ensureTrailingDot($zoneName);
        $zoneData = $this->client->getZone($apiName, false);
        return $zoneData !== null;
    }

    public function getZoneById(int $domainId): ?array
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return null;
        }
        $zoneData = $this->client->getZone(self::ensureTrailingDot($zoneName), false);
        if ($zoneData === null) {
            return null;
        }

        return [
            'id' => $domainId,
            'name' => $zoneName,
            'type' => strtoupper($zoneData['kind'] ?? ''),
            'master' => self::formatMasters($zoneData['masters'] ?? []),
            'dnssec' => $zoneData['dnssec'] ?? false,
        ];
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

        $stmt = $this->db->prepare("SELECT " . CanonicalZoneSql::canonicalIdColumn('', $this->allocatesZoneIdsLocally()) . " FROM zones WHERE zone_name = :name");
        $stmt->execute([':name' => $zoneName]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    public function getZoneTypeById(int $domainId): string
    {
        return ($this->resolveKindAndMaster($domainId)['type'] ?? '') ?: 'NATIVE';
    }

    public function getZoneMasterById(int $domainId): ?string
    {
        return ($this->resolveKindAndMaster($domainId)['master'] ?? '') ?: null;
    }

    /**
     * Reads the kind and master from the local zones row; only a row with no kind cached
     * (written before the sync stored types) costs a round trip to PowerDNS.
     *
     * @return array{type: string, master: string}|null Null when neither side knows the zone
     */
    private function resolveKindAndMaster(int $domainId): ?array
    {
        $row = $this->resolveCanonicalZoneRow($domainId);
        if ($row === null) {
            return null;
        }
        if (!empty($row['zone_type'])) {
            return ['type' => $row['zone_type'], 'master' => (string)($row['zone_master'] ?? '')];
        }

        $zoneData = $this->client->getZone(self::ensureTrailingDot($row['zone_name']), false);
        if ($zoneData === null) {
            return null;
        }

        return [
            'type' => strtoupper($zoneData['kind'] ?? ''),
            'master' => self::formatMasters($zoneData['masters'] ?? []),
        ];
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

        if ($zoneId === null || $zoneId === 0) {
            return null;
        }

        // Fetch record from API to verify it still exists
        $apiZoneName = self::ensureTrailingDot($zoneName);
        $apiRecordName = self::ensureTrailingDot($decoded['name']);
        $rrsetData = $this->getRRsetFromApi($apiZoneName, $apiRecordName, $decoded['type']);

        if ($rrsetData === null) {
            return null;
        }

        // Find the specific record within the RRset
        $targetContent = $this->formatRecordContent($decoded['type'], $decoded['content'], $decoded['prio']);
        $found = false;
        $disabled = 0;
        foreach ($rrsetData['records'] as $r) {
            if (self::contentMatchesApi($r['content'], $targetContent)) {
                $disabled = ($r['disabled'] ?? false) ? 1 : 0;
                $found = true;
                break;
            }
        }

        if (!$found) {
            return null;
        }

        return [
            'id' => $recordId,
            'domain_id' => $zoneId,
            'name' => $decoded['name'],
            'type' => $decoded['type'],
            'content' => $decoded['content'],
            'ttl' => $rrsetData['ttl'],
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

    public function getZoneStats(bool $withDnssec = true): array
    {
        return $this->client->getAllZoneStats($withDnssec);
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

    public function getRecordsByName(int $domainId, string $name, ?string $type = null): array
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return [];
        }

        $zoneData = $this->client->getZoneRrset(
            self::ensureTrailingDot($zoneName),
            self::ensureTrailingDot($name),
            $type
        );
        if ($zoneData === null) {
            return [];
        }

        // PowerDNS below 4.7 ignores the filter and hands back the whole zone,
        // so narrow the result here rather than trusting the server to have done it
        $records = $this->flattenRrsets($zoneData, $domainId, $zoneName);
        return array_values(array_filter(
            $records,
            fn(array $r): bool => strcasecmp($r['name'], $name) === 0
                && ($type === null || $r['type'] === $type)
        ));
    }

    public function findRecordsByName(string $name, ?string $type = null): array
    {
        // PowerDNS stores names in lower case and echoes the query's case on a filtered read
        $name = strtolower(rtrim($name, '.'));

        // A name lives in the most specific zone that holds it, so read that zone's
        // RRsets: the search endpoint matches contents too and stops at its cap
        $zoneId = $this->bestMatchingZoneId($name, false);
        if ($zoneId > 0) {
            return $this->getRecordsByName($zoneId, $name, $type);
        }

        // PowerDNS may hold a zone the local zones table has not synced yet
        return $this->exactRecordSearch(
            [$name],
            fn(array $r): bool => strcasecmp($r['name'], $name) === 0 && ($type === null || $r['type'] === $type)
        )[0];
    }

    public function findRecordsByContent(string $content, ?string $type = null): array
    {
        return $this->contentMatches($content, $type)[0];
    }

    public function findAllRecordsByContent(string $content, ?string $type = null): ?array
    {
        [$records, $complete] = $this->contentMatches($content, $type);

        return $complete ? $records : null;
    }

    /**
     * Name-like content is stored with or without its trailing dot depending on how it
     * was written, and the search matches the stored form, so both are asked for.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool} The matches, and whether the search saw all of them
     */
    private function contentMatches(string $content, ?string $type): array
    {
        // search-data reads * and ? as wildcards with no escape, so a wildcard name cannot
        // be asked for exactly; MX and NS content never holds one, which is what callers check
        if (strpbrk($content, '*?') !== false) {
            return [[], true];
        }
        $bare = rtrim($content, '.');

        return $this->exactRecordSearch(
            [$bare, $bare . '.'],
            fn(array $r): bool => $r['content'] === $content && ($type === null || $r['type'] === $type)
        );
    }

    /**
     * Exact (not wildcard) search-data queries, filtered by the caller's predicate. The
     * endpoint matches names and contents alike and stops at its limit, so a query that
     * fills the limit may have left matches out.
     *
     * @param list<string> $queries
     * @param callable(array): bool $match
     * @return array{0: list<array<string, mixed>>, 1: bool} The matches, and whether every query stayed under the limit
     */
    private function exactRecordSearch(array $queries, callable $match): array
    {
        $matches = [];
        $complete = true;
        foreach (array_unique($queries) as $query) {
            $results = $this->client->searchData($query, 'record', self::EXACT_SEARCH_LIMIT);
            $complete = $complete && count($results) < self::EXACT_SEARCH_LIMIT;
            foreach ($this->mapSearchResults($results)['records'] as $record) {
                if ($match($record)) {
                    $matches[$record['id']] = $record;
                }
            }
        }

        return [array_values($matches), $complete];
    }

    public function getSOARecord(int $domainId): string
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return '';
        }
        $apiName = self::ensureTrailingDot($zoneName);
        $zoneData = $this->client->getZoneRrset($apiName, self::ensureTrailingDot(DnsHelper::zoneApexName($zoneName)), 'SOA');
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
        return $this->bestMatchingZoneId($reverseName, true);
    }

    /**
     * The most specific local zone that holds the name, or -1 when none does.
     */
    private function bestMatchingZoneId(string $name, bool $reverseOnly): int
    {
        // Return the canonical id, the value every other zone id in this backend
        // carries. Returning zones.id let the resolver prefer an unrelated row
        // whose domain_id happened to equal it, writing the PTR into that zone.
        $canonicalId = CanonicalZoneSql::canonicalIdColumn('', $this->allocatesZoneIdsLocally());
        $stmt = $this->db->query("SELECT $canonicalId AS id, zone_name FROM zones WHERE zone_name IS NOT NULL");
        $foundId = -1;
        $bestLength = -1;

        $lowerName = strtolower($name);
        $bestIsVariant = false;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $zoneName = strtolower((string)$row['zone_name']);
            $apexLength = strlen(DnsHelper::zoneApexName($zoneName));
            if ($reverseOnly && !str_ends_with(DnsHelper::zoneApexName($zoneName), '.arpa')) {
                continue;
            }
            // Match on a label boundary (apex or suffix) and keep the longest
            // (most specific) zone, so a record is not dropped into a shorter
            // zone that merely shares a trailing substring. On an equal apex the
            // base zone wins over its view variant, as in the DDNS zone pick.
            if (!DnsHelper::isWithinZone($lowerName, $zoneName)) {
                continue;
            }
            $isVariant = DnsHelper::isViewVariant($zoneName);
            if ($apexLength > $bestLength || ($apexLength === $bestLength && $bestIsVariant && !$isVariant)) {
                $bestLength = $apexLength;
                $bestIsVariant = $isVariant;
                $foundId = (int)$row['id'];
            }
        }
        return $foundId;
    }

    // ---------------------------------------------------------------
    // Zone list operations
    // ---------------------------------------------------------------

    public function getZones(bool $withDnssec = true): array
    {
        try {
            $apiZones = $this->client->getAllZones($withDnssec);
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get zones from API: {error}', ['error' => $e->getMessage()]);
            $this->apiStatus->recordError($e->getMessage(), [
                'endpoint' => 'zones',
                'http_code' => $e->getDetail('http_code', $e->getCode()),
                'url' => $e->getDetail('url'),
            ]);
            return [];
        }
        $this->apiStatus->clearError();

        // Pull kind/masters from the same /zones list response so type stays in
        // sync when changed externally (e.g. pdnsutil set-kind). Reading the
        // local zones cache here would lock in stale values and prevent
        // ZoneSyncService from ever detecting the drift.
        // Same endpoint as the call above, so the two reads share one response
        $apiKinds = null;
        try {
            $apiKinds = $this->client->getAllZoneKinds($withDnssec);
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to get zone kinds from API: {error}', ['error' => $e->getMessage()]);
        }

        // Always read the local cache: it provides id mapping, plus a fallback
        // for type/master if the kinds call failed. Without that fallback, an
        // empty type would feed back into ZoneSyncService and wipe valid
        // cached metadata on the next sync.
        $localZones = [];
        if (!empty($apiZones)) {
            $stmt = $this->db->query("SELECT id, domain_id, zone_name, zone_type, zone_master FROM zones WHERE zone_name IS NOT NULL");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $localZones[$row['zone_name']] = $row;
            }
        }

        $zones = [];
        foreach ($apiZones as $zone) {
            $apiName = $zone->getName();
            $name = rtrim($apiName, '.');
            $local = $localZones[$name] ?? null;
            $kind = $apiKinds[$apiName] ?? null;

            if ($kind !== null) {
                $type = $kind['kind'];
                $master = self::formatMasters($kind['masters']);
            } else {
                // Bulk kinds call failed - keep last-known values from cache so
                // a transient API blip doesn't propagate empty values back into
                // the cache via ZoneSyncService.
                $type = strtoupper((string)($local['zone_type'] ?? ''));
                $master = (string)($local['zone_master'] ?? '');
            }

            $zones[] = [
                'id' => $local !== null ? (int)($local['domain_id'] ?: $local['id']) : 0,
                'name' => $name,
                'type' => $type,
                'master' => $master,
                'dnssec' => $zone->isSecured(),
                'account' => (string)($kind['account'] ?? ''),
            ];
        }

        return $zones;
    }

    public function getZonesByIds(array $domainIds): array
    {
        $zones = [];
        // Name and kind come from the local zones row; only a row with no cached kind
        // costs a round trip (getZoneTypeById).
        foreach (array_unique(array_map('intval', $domainIds)) as $id) {
            $row = $this->resolveCanonicalZoneRow($id);
            if ($row !== null) {
                $type = !empty($row['zone_type']) ? (string)$row['zone_type'] : $this->getZoneTypeById($id);
                $zones[] = ['id' => $id, 'name' => (string)$row['zone_name'], 'type' => $type];
            }
        }
        usort($zones, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $zones;
    }

    public function countZones(): int
    {
        // Only the number is needed, so skip the per-zone DNSSEC lookup
        return count($this->getZones(false));
    }

    public function countRecords(): ?int
    {
        // PowerDNS keeps no global record count and the zone list carries none
        return null;
    }

    public function getZoneSoaHealth(string $zoneName, string $kind): ?array
    {
        // Optimization hint only - skip the API call when the caller is sure
        // the zone is SLAVE. The post-fetch check below is the correctness gate.
        if (strtoupper($kind) === 'SLAVE') {
            return ['is_disabled' => false, 'is_missing_soa' => false];
        }

        // Only the apex SOA matters here, so ask for just that RRset - the
        // client serves this from a whole body it already holds for the zone.
        $apiName = self::ensureTrailingDot($zoneName);
        $apexName = self::ensureTrailingDot(DnsHelper::zoneApexName($zoneName));
        $zoneData = $this->client->getZoneRrset($apiName, $apexName, 'SOA');
        if ($zoneData === null) {
            return null;
        }

        // Re-check kind from the authoritative API response: a caller's stale
        // local cache may report MASTER for what's now SLAVE in PowerDNS.
        if (strtoupper($zoneData['kind'] ?? '') === 'SLAVE') {
            return ['is_disabled' => false, 'is_missing_soa' => false];
        }

        foreach ($zoneData['rrsets'] ?? [] as $rrset) {
            if (($rrset['type'] ?? '') !== 'SOA') {
                continue;
            }
            // Only SOA at the zone apex makes the zone authoritative; an SOA
            // under a subname doesn't, and shouldn't drive the badge state.
            if (($rrset['name'] ?? '') !== $apexName) {
                continue;
            }
            // Aggregate across all SOA records: any disabled SOA disables the
            // zone, matching PowerDNS auth-server behavior and the SQL backend.
            $records = $rrset['records'] ?? [];
            if (empty($records)) {
                continue;
            }
            $isDisabled = false;
            foreach ($records as $record) {
                if (!empty($record['disabled'])) {
                    $isDisabled = true;
                    break;
                }
            }
            return ['is_disabled' => $isDisabled, 'is_missing_soa' => false];
        }

        return ['is_disabled' => false, 'is_missing_soa' => true];
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

        return $this->flattenRrsets($zoneData, $domainId, $zoneName);
    }

    /**
     * Expand a zone body's RRsets into one row per record, the shape the
     * repositories and the SQL backend both expect.
     */
    private function flattenRrsets(array $zoneData, int $domainId, string $zoneName): array
    {
        $records = [];
        foreach ($zoneData['rrsets'] ?? [] as $rrset) {
            $type = $rrset['type'] ?? '';
            if ($type === '') {
                continue; // Skip ENT records
            }

            $name = rtrim($rrset['name'] ?? '', '.');
            $ttl = $rrset['ttl'] ?? 3600;

            // Extract RRset-level comment (first comment if present)
            $rrsetComments = $rrset['comments'] ?? [];
            $rrsetComment = !empty($rrsetComments) ? ($rrsetComments[0]['content'] ?? null) : null;
            $rrsetCommentAccount = !empty($rrsetComments) ? ($rrsetComments[0]['account'] ?? null) : null;
            $rrsetCommentModifiedAt = !empty($rrsetComments) && isset($rrsetComments[0]['modified_at'])
                ? (int)$rrsetComments[0]['modified_at'] : null;

            foreach ($rrset['records'] ?? [] as $record) {
                $content = $record['content'] ?? '';
                $prio = 0;

                // Priority-bearing types carry the preference in front of the content
                if (RecordType::hasPriority($type)) {
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
                    'api_comment' => $rrsetComment,
                    'api_comment_account' => $rrsetCommentAccount,
                    'api_comment_modified_at' => $rrsetCommentModifiedAt,
                    // PowerDNS 4.9.8+ sends this only from LMDB (gsql never stores it), and every
                    // write restamps the whole RRset
                    'modified_at' => isset($record['modified_at']) ? (int)$record['modified_at'] : null,
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
        return $this->mapSearchResults($this->client->searchData('*' . $query . '*', $objectType, $max));
    }

    /**
     * Map search-data results to the zone, record and comment rows the rest of the
     * application reads, with local zone ids and trailing dots stripped from content.
     *
     * @param array<int, array<string, mixed>> $apiResults
     * @return array{zones: array, records: array, comments: array}
     */
    private function mapSearchResults(array $apiResults): array
    {
        $zones = [];
        $records = [];
        $comments = [];

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
            } elseif ($objType === 'comment') {
                $zoneName = rtrim($result['zone'] ?? '', '.');
                $domainId = 0;
                if (isset($localZones[$zoneName])) {
                    $domainId = (int)($localZones[$zoneName]['domain_id'] ?: $localZones[$zoneName]['id']);
                }
                $comments[] = [
                    'domain_id' => $domainId,
                    'zone_name' => $zoneName,
                    'name' => $name,
                    'type' => $result['type'] ?? '',
                    'comment' => $result['content'] ?? '',
                ];
            } elseif ($objType === 'record') {
                $content = $result['content'] ?? '';
                $type = $result['type'] ?? '';
                $prio = 0;

                if (RecordType::hasPriority($type)) {
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

        return ['zones' => $zones, 'records' => $records, 'comments' => $comments];
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

    public function supportsLocalWriteTransaction(): bool
    {
        return false;
    }

    public function recordIdsAreNumeric(): bool
    {
        return false;
    }

    public function managesSoaRecord(): bool
    {
        return true;
    }

    public function allocatesZoneIdsLocally(): bool
    {
        return true;
    }

    public function providesSignedSerial(): bool
    {
        return true;
    }

    public function supportsRecordCountSort(): bool
    {
        return false;
    }

    public function supportsGroupSort(): bool
    {
        return false;
    }

    public function supportsZoneRetrieve(): bool
    {
        return true;
    }

    public function supportsZoneNotify(): bool
    {
        return true;
    }

    public function syncsZoneListFromServer(): bool
    {
        return true;
    }

    public function hasSoaEditApi(int $domainId): bool
    {
        $zoneName = $this->getZoneNameByLocalId($domainId);
        if ($zoneName === null) {
            return false;
        }

        $apiZoneName = self::ensureTrailingDot($zoneName);
        $zoneData = $this->client->getZone($apiZoneName, false);

        $mode = strtoupper(trim((string)($zoneData['soa_edit_api'] ?? '')));

        // OFF is PowerDNS's spelling of "never edit the serial", the same as unset
        return $zoneData !== null && $mode !== '' && $mode !== 'OFF';
    }

    public function setZoneSerialPolicy(int $domainId, string $zoneName, array $properties): bool
    {
        if ($properties === []) {
            return true;
        }

        return $this->client->updateZoneProperties(self::ensureTrailingDot($zoneName), $properties);
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
     * See resolveCanonicalZoneRow() for which row an ID resolves to.
     */
    private function getZoneNameByLocalId(int $id): ?string
    {
        return $this->resolveCanonicalZoneRow($id)['zone_name'] ?? null;
    }

    /**
     * Resolve the zones row a Poweradmin zone ID refers to.
     * Callers write the cache back to row['id'], so resolving before the write matters.
     *
     * @return array{id: int|string, zone_name: string, zone_type: ?string, zone_master: ?string}|null
     */
    private function resolveCanonicalZoneRow(int $id): ?array
    {
        $stmt = $this->db->prepare(CanonicalZoneSql::selectByZoneId('id, zone_name, zone_type, zone_master'));
        CanonicalZoneSql::bindZoneId($stmt, $id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function ensureTrailingDot(string $name): string
    {
        return str_ends_with($name, '.') ? $name : $name . '.';
    }

    /**
     * Split a comma-separated list of master IPs into the array PowerDNS expects.
     * The forms collect several masters as a single comma-separated string.
     *
     * @return string[]
     */
    private static function parseMasters(string $masters): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $masters)), 'strlen'));
    }

    /**
     * Flatten the masters array PowerDNS returns back into the stored string form.
     * getAllZoneKinds() normalizes the shape for its callers, but a raw zone read hands
     * us whatever the server put in the JSON, so guard it here.
     */
    private static function formatMasters(mixed $masters): string
    {
        if (!is_array($masters)) {
            return '';
        }

        return implode(',', array_filter($masters, 'is_scalar'));
    }

    private static function contentMatchesApi(string $apiContent, string $dbFormattedContent): bool
    {
        return rtrim($apiContent, '.') === rtrim($dbFormattedContent, '.');
    }

    private function formatRecordContent(string $type, string $content, int $prio): string
    {
        if (RecordType::hasPriority($type)) {
            return $prio . ' ' . self::ensureTrailingDot($content);
        }

        if ($type === 'SOA') {
            $parts = explode(' ', $content);
            if (count($parts) >= 7) {
                $parts[0] = self::ensureTrailingDot($parts[0]);
                $parts[1] = self::ensureTrailingDot($parts[1]);
                return implode(' ', $parts);
            }
        }

        $hostnameTypes = ['CNAME', 'NS', 'PTR', 'AFSDB', 'DNAME', 'ALIAS'];
        if (in_array($type, $hostnameTypes, true)) {
            return self::ensureTrailingDot($content);
        }

        return $content;
    }

    /** The form the API backend reads hostname content in, which is also what the duplicate check compares. */
    public static function stripTrailingDotFromContent(string $type, string $content): string
    {
        $hostnameTypes = ['CNAME', 'NS', 'MX', 'KX', 'PTR', 'SRV', 'AFSDB', 'DNAME', 'ALIAS'];
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
        $zoneData = $this->client->getZoneRrset($apiZoneName, $apiRecordName, $type);
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
