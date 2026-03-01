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
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\TableNameService;

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
    private PDOCommon $db;
    private ConfigurationInterface $config;
    private TableNameService $tableNameService;

    public function __construct(PowerdnsApiClient $client, PDOCommon $db, ConfigurationInterface $config)
    {
        $this->client = $client;
        $this->db = $db;
        $this->config = $config;
        $this->tableNameService = new TableNameService($config);
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
            error_log(sprintf("Failed to fetch current RRset for '%s %s' from API - aborting to prevent data loss", $apiRecordName, $type));
            return false;
        }

        // Build the full RRset including existing records + new record
        $records = $rrsetData['records'];
        $records[] = [
            'content' => $this->formatRecordContent($type, $content, $prio),
            'disabled' => false,
        ];

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
                error_log(sprintf("Failed to fetch old RRset for '%s %s' from API - aborting to prevent data loss", $oldName, $oldType));
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
                error_log(sprintf("Failed to fetch new RRset for '%s %s' from API - aborting to prevent data loss", $name, $type));
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
                error_log(sprintf("Failed to fetch RRset for '%s %s' from API - aborting to prevent data loss", $name, $type));
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
            error_log(sprintf("Failed to fetch current RRset for '%s %s' from API - aborting to prevent data loss", $name, $type));
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

        return $content;
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

        error_log(sprintf("Failed to find domain ID for '%s' after API creation (retried %d times)", $domain, $maxRetries));
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

        error_log(sprintf("Failed to find record ID for '%s %s %s' after API creation (retried %d times)", $name, $type, $content, $maxRetries));
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
