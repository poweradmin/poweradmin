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
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\TableNameService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SQL-based DNS backend provider.
 *
 * Performs DNS data operations directly against the PowerDNS database tables.
 * This is the default backend and preserves the existing behavior.
 */
class SqlDnsBackendProvider implements DnsBackendProvider
{
    private PDO $db;
    private ConfigurationInterface $config;
    private TableNameService $tableNameService;
    private LoggerInterface $logger;

    public function __construct(PDO $db, ConfigurationInterface $config, ?LoggerInterface $logger = null)
    {
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
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->prepare("INSERT INTO $domainsTable (name, type) VALUES (:domain, :type)");
        $stmt->bindValue(':domain', $domain, PDO::PARAM_STR);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->execute();

        $domainId = (int)$this->db->lastInsertId();

        if ($type === 'SLAVE' && $slaveMaster !== '') {
            $stmt = $this->db->prepare("UPDATE $domainsTable SET master = :master WHERE id = :id");
            $stmt->bindValue(':master', $slaveMaster, PDO::PARAM_STR);
            $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $domainId;
    }

    public function deleteZone(int $domainId, string $zoneName): bool
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $domainmetadataTable = $this->tableNameService->getTable(PdnsTable::DOMAINMETADATA);
        $cryptokeysTable = $this->tableNameService->getTable(PdnsTable::CRYPTOKEYS);

        $stmt = $this->db->prepare("DELETE FROM $recordsTable WHERE domain_id = :id");
        $stmt->execute([':id' => $domainId]);

        $stmt = $this->db->prepare("DELETE FROM $domainmetadataTable WHERE domain_id = :id");
        $stmt->execute([':id' => $domainId]);

        $stmt = $this->db->prepare("DELETE FROM $cryptokeysTable WHERE domain_id = :id");
        $stmt->execute([':id' => $domainId]);

        $stmt = $this->db->prepare("DELETE FROM $domainsTable WHERE id = :id");
        $stmt->execute([':id' => $domainId]);

        return true;
    }

    public function updateZoneType(int $domainId, string $type): bool
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $params = [':type' => $type, ':id' => $domainId];
        $masterClause = '';

        if ($type !== 'SLAVE') {
            $masterClause = ', master = :master';
            $params[':master'] = '';
        }

        $stmt = $this->db->prepare("UPDATE $domainsTable SET type = :type{$masterClause} WHERE id = :id");
        $stmt->execute($params);

        return true;
    }

    public function updateZoneMaster(int $domainId, string $masterIp): bool
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->prepare("UPDATE $domainsTable SET master = :master WHERE id = :id");
        $stmt->execute([':master' => $masterIp, ':id' => $domainId]);

        return true;
    }

    public function updateZoneAccount(int $domainId, string $account): bool
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->prepare("UPDATE $domainsTable SET account = :account WHERE id = :id");
        $stmt->execute([':account' => $account, ':id' => $domainId]);

        return true;
    }

    // ---------------------------------------------------------------
    // Record operations
    // ---------------------------------------------------------------

    public function addRecord(int $domainId, string $name, string $type, string $content, int $ttl, int $prio): bool
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("INSERT INTO $recordsTable (domain_id, name, type, content, ttl, prio) VALUES (:domain_id, :name, :type, :content, :ttl, :prio)");
        $stmt->bindValue(':domain_id', $domainId, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->bindValue(':content', $content, PDO::PARAM_STR);
        $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
        $stmt->bindValue(':prio', $prio, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    public function addRecordGetId(int $domainId, string $name, string $type, string $content, int $ttl, int $prio): int|string|null
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("INSERT INTO $recordsTable (domain_id, name, type, content, ttl, prio) VALUES (:domain_id, :name, :type, :content, :ttl, :prio)");
        $stmt->bindValue(':domain_id', $domainId, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->bindValue(':content', $content, PDO::PARAM_STR);
        $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
        $stmt->bindValue(':prio', $prio, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$this->db->lastInsertId();
    }

    public function createRecordAtomic(int $domainId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled = 0): int|string|null
    {
        // If already inside a caller's transaction (e.g. RRSet replace, bulk ops),
        // just do the INSERT without managing the transaction ourselves.
        $ownsTransaction = !$this->db->inTransaction();

        $maxRetries = $ownsTransaction ? 3 : 1;
        $retryDelay = 50000; // 50ms in microseconds

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                if ($ownsTransaction) {
                    $this->db->beginTransaction();
                }

                $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);
                $stmt = $this->db->prepare(
                    "INSERT INTO $recordsTable (domain_id, name, type, content, ttl, prio, disabled)
                     VALUES (:domain_id, :name, :type, :content, :ttl, :prio, :disabled)"
                );
                $stmt->bindValue(':domain_id', $domainId, PDO::PARAM_INT);
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':type', $type, PDO::PARAM_STR);
                $stmt->bindValue(':content', $content, PDO::PARAM_STR);
                $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
                $stmt->bindValue(':prio', $prio, PDO::PARAM_INT);
                $stmt->bindValue(':disabled', $disabled, PDO::PARAM_INT);
                $stmt->execute();

                $id = (int)$this->db->lastInsertId();

                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return $id;
            } catch (\Exception $e) {
                if ($ownsTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                if ($ownsTransaction && $this->isDeadlockError($e) && $attempt < $maxRetries) {
                    $this->logger->warning('Deadlock detected on attempt {attempt}, retrying', [
                        'attempt' => $attempt,
                        'message' => $e->getMessage(),
                    ]);
                    usleep($retryDelay * $attempt);
                    continue;
                }

                throw $e;
            }
        }

        return null;
    }

    public function editRecord(int|string $recordId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled): bool
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("UPDATE $recordsTable SET name = ?, type = ?, content = ?, ttl = ?, prio = ?, disabled = ? WHERE id = ?");
        $stmt->execute([$name, $type, $content, $ttl, $prio, $disabled, $recordId]);

        return true;
    }

    public function deleteRecord(int|string $recordId): bool
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("DELETE FROM $recordsTable WHERE id = ?");
        $stmt->execute([$recordId]);

        return true;
    }

    public function deleteRecordsByDomainId(int $domainId): bool
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("DELETE FROM $recordsTable WHERE domain_id = :id");
        $stmt->execute([':id' => $domainId]);

        return true;
    }

    // ---------------------------------------------------------------
    // Zone read methods
    // ---------------------------------------------------------------

    public function zoneExists(string $zoneName): bool
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $stmt = $this->db->prepare("SELECT COUNT(id) FROM $domainsTable WHERE name = :name");
        $stmt->execute([':name' => $zoneName]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function getZoneById(int $domainId): ?array
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $cryptokeysTable = $this->tableNameService->getTable(PdnsTable::CRYPTOKEYS);
        $domainmetadataTable = $this->tableNameService->getTable(PdnsTable::DOMAINMETADATA);

        $stmt = $this->db->prepare(
            "SELECT d.id, d.name, d.type, d.master,
                    EXISTS(SELECT 1 FROM $cryptokeysTable ck WHERE ck.domain_id = d.id) OR
                    EXISTS(SELECT 1 FROM $domainmetadataTable dm WHERE dm.domain_id = d.id AND dm.kind = 'PRESIGNED')
                    AS dnssec
             FROM $domainsTable d WHERE d.id = :id"
        );
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'type' => $row['type'] ?: 'NATIVE',
            'master' => $row['master'] ?? '',
            'dnssec' => (bool)$row['dnssec'],
        ];
    }

    public function getZoneNameById(int $domainId): ?string
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $stmt = $this->db->prepare("SELECT name FROM $domainsTable WHERE id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $name = $stmt->fetchColumn();
        return $name !== false ? $name : null;
    }

    public function getZoneIdByName(string $zoneName): ?int
    {
        if (empty($zoneName)) {
            return null;
        }
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $stmt = $this->db->prepare("SELECT id FROM $domainsTable WHERE name = :name");
        $stmt->execute([':name' => $zoneName]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    public function getZoneTypeById(int $domainId): string
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $stmt = $this->db->prepare("SELECT type FROM $domainsTable WHERE id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $type = $stmt->fetchColumn();
        return $type ?: 'NATIVE';
    }

    public function getZoneMasterById(int $domainId): ?string
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $stmt = $this->db->prepare("SELECT master FROM $domainsTable WHERE type = 'SLAVE' AND id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }

    // ---------------------------------------------------------------
    // Record read methods
    // ---------------------------------------------------------------

    public function getRecordById(int|string $recordId): ?array
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $stmt = $this->db->prepare(
            "SELECT id, domain_id, name, type, content, ttl, prio, disabled
             FROM $recordsTable WHERE id = :id AND type IS NOT NULL AND type != ''"
        );
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['content'])) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'domain_id' => (int)$row['domain_id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'content' => $row['content'],
            'ttl' => (int)$row['ttl'],
            'prio' => (int)$row['prio'],
            'disabled' => (int)$row['disabled'],
        ];
    }

    public function getZoneIdFromRecordId(int|string $recordId): int
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $stmt = $this->db->prepare("SELECT domain_id FROM $recordsTable WHERE id = :id");
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function getZoneStats(): array
    {
        return [];
    }

    public function countZoneRecords(int $domainId): int
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $stmt = $this->db->prepare(
            "SELECT COUNT(id) FROM $recordsTable WHERE domain_id = :did AND type IS NOT NULL AND type != ''"
        );
        $stmt->bindValue(':did', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function recordExists(int $domainId, string $name, string $type, string $content): bool
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM $recordsTable
             WHERE domain_id = :did AND name = :name AND type = :type AND content = :content"
        );
        $stmt->execute([':did' => $domainId, ':name' => $name, ':type' => $type, ':content' => $content]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function getRecordsByZoneId(int $domainId, ?string $type = null): array
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $query = "SELECT id, domain_id, name, type, content, ttl, prio, disabled
                  FROM $recordsTable
                  WHERE domain_id = :did AND type IS NOT NULL AND type != ''";
        $params = [':did' => $domainId];

        if ($type !== null) {
            $query .= " AND type = :type";
            $params[':type'] = $type;
        }
        $query .= " ORDER BY type, name";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSOARecord(int $domainId): string
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $stmt = $this->db->prepare("SELECT content FROM $recordsTable WHERE type = 'SOA' AND domain_id = :did");
        $stmt->bindValue(':did', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() ?: '';
    }

    public function getBestMatchingReverseZoneId(string $reverseName): int
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $match = 72;
        $foundId = -1;

        $stmt = $this->db->prepare(
            "SELECT name, id FROM $domainsTable WHERE name LIKE :pattern ORDER BY length(name) DESC"
        );
        $stmt->execute([':pattern' => '%.arpa']);

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pos = stripos($reverseName, $r['name']);
            if ($pos !== false && $pos < $match) {
                $match = $pos;
                $foundId = (int)$r['id'];
            }
        }
        return $foundId;
    }

    // ---------------------------------------------------------------
    // Zone list operations
    // ---------------------------------------------------------------

    public function getZones(): array
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $cryptokeysTable = $this->tableNameService->getTable(PdnsTable::CRYPTOKEYS);
        $domainmetadataTable = $this->tableNameService->getTable(PdnsTable::DOMAINMETADATA);

        $query = "SELECT d.id, d.name, d.type, d.master,
                    EXISTS(SELECT 1 FROM $cryptokeysTable ck WHERE ck.domain_id = d.id) OR
                    EXISTS(SELECT 1 FROM $domainmetadataTable dm WHERE dm.domain_id = d.id AND dm.kind = 'PRESIGNED')
                    AS dnssec
                  FROM $domainsTable d
                  ORDER BY d.name";

        $stmt = $this->db->query($query);
        $zones = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $zones[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'type' => $row['type'],
                'master' => $row['master'] ?? '',
                'dnssec' => (bool)$row['dnssec'],
            ];
        }
        return $zones;
    }

    public function getZoneByName(string $zoneName): ?array
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $cryptokeysTable = $this->tableNameService->getTable(PdnsTable::CRYPTOKEYS);
        $domainmetadataTable = $this->tableNameService->getTable(PdnsTable::DOMAINMETADATA);

        $stmt = $this->db->prepare(
            "SELECT d.id, d.name, d.type, d.master,
                    EXISTS(SELECT 1 FROM $cryptokeysTable ck WHERE ck.domain_id = d.id) OR
                    EXISTS(SELECT 1 FROM $domainmetadataTable dm WHERE dm.domain_id = d.id AND dm.kind = 'PRESIGNED')
                    AS dnssec
             FROM $domainsTable d WHERE d.name = :name"
        );
        $stmt->execute([':name' => $zoneName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'master' => $row['master'] ?? '',
            'dnssec' => (bool)$row['dnssec'],
        ];
    }

    // ---------------------------------------------------------------
    // Record read operations
    // ---------------------------------------------------------------

    public function getZoneRecords(int $domainId, string $zoneName): array
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare(
            "SELECT id, domain_id, name, type, content, ttl, prio, disabled
             FROM $recordsTable
             WHERE domain_id = :domain_id AND type IS NOT NULL AND type != ''
             ORDER BY name, type"
        );
        $stmt->bindValue(':domain_id', $domainId, PDO::PARAM_INT);
        $stmt->execute();

        $records = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $records[] = [
                'id' => (int)$row['id'],
                'domain_id' => (int)$row['domain_id'],
                'name' => $row['name'],
                'type' => $row['type'],
                'content' => $row['content'],
                'ttl' => (int)$row['ttl'],
                'prio' => (int)$row['prio'],
                'disabled' => (int)$row['disabled'],
            ];
        }
        return $records;
    }

    // ---------------------------------------------------------------
    // Search operations
    // ---------------------------------------------------------------

    public function searchDnsData(string $query, string $objectType = 'all', int $max = 100): array
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $zones = [];
        $records = [];
        $pattern = '%' . $query . '%';

        if ($objectType === 'all' || $objectType === 'zone') {
            $sql = "SELECT id, name, type FROM $domainsTable WHERE name LIKE :pattern ORDER BY name";
            if ($max > 0) {
                $sql .= " LIMIT :max";
            }
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':pattern', $pattern, PDO::PARAM_STR);
            if ($max > 0) {
                $stmt->bindValue(':max', $max, PDO::PARAM_INT);
            }
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $zones[] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'type' => $row['type'],
                ];
            }
        }

        if ($objectType === 'all' || $objectType === 'record') {
            $sql = "SELECT r.id, r.domain_id, r.name, r.type, r.content, r.ttl, r.prio, r.disabled, d.name AS zone_name
                 FROM $recordsTable r
                 LEFT JOIN $domainsTable d ON r.domain_id = d.id
                 WHERE (r.name LIKE :pattern1 OR r.content LIKE :pattern2)
                   AND r.type IS NOT NULL AND r.type != ''
                 ORDER BY r.name";
            if ($max > 0) {
                $sql .= " LIMIT :max";
            }
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':pattern1', $pattern, PDO::PARAM_STR);
            $stmt->bindValue(':pattern2', $pattern, PDO::PARAM_STR);
            if ($max > 0) {
                $stmt->bindValue(':max', $max, PDO::PARAM_INT);
            }
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $records[] = [
                    'id' => (int)$row['id'],
                    'domain_id' => (int)$row['domain_id'],
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'content' => $row['content'],
                    'ttl' => (int)$row['ttl'],
                    'prio' => (int)$row['prio'],
                    'disabled' => (int)$row['disabled'],
                    'zone_name' => $row['zone_name'] ?? '',
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
        // In SQL mode, this is handled by SOARecordManager directly.
        // This method exists for interface compliance; actual SOA serial
        // updates are delegated by RecordManager to SOARecordManager.
        return true;
    }

    // ---------------------------------------------------------------
    // Supermaster operations
    // ---------------------------------------------------------------

    public function addSupermaster(string $masterIp, string $nsName, string $account): bool
    {
        $supermastersTable = $this->tableNameService->getTable(PdnsTable::SUPERMASTERS);

        $stmt = $this->db->prepare("INSERT INTO $supermastersTable (ip, nameserver, account) VALUES (:ip, :ns, :account)");
        $stmt->execute([':ip' => $masterIp, ':ns' => $nsName, ':account' => $account]);

        return true;
    }

    public function deleteSupermaster(string $masterIp, string $nsName): bool
    {
        $supermastersTable = $this->tableNameService->getTable(PdnsTable::SUPERMASTERS);

        $stmt = $this->db->prepare("DELETE FROM $supermastersTable WHERE ip = :ip AND nameserver = :ns");
        $stmt->execute([':ip' => $masterIp, ':ns' => $nsName]);

        return true;
    }

    public function getSupermasters(): array
    {
        $supermastersTable = $this->tableNameService->getTable(PdnsTable::SUPERMASTERS);

        $result = $this->db->query("SELECT ip, nameserver, account FROM $supermastersTable");

        $supermasters = [];
        while ($r = $result->fetch()) {
            $supermasters[] = [
                'master_ip' => $r['ip'],
                'ns_name' => $r['nameserver'],
                'account' => $r['account'],
            ];
        }

        return $supermasters;
    }

    public function updateSupermaster(string $oldMasterIp, string $oldNsName, string $newMasterIp, string $newNsName, string $account): bool
    {
        $supermastersTable = $this->tableNameService->getTable(PdnsTable::SUPERMASTERS);

        $stmt = $this->db->prepare("UPDATE $supermastersTable SET ip = :new_ip, nameserver = :new_ns, account = :account WHERE ip = :old_ip AND nameserver = :old_ns");
        $stmt->execute([
            ':new_ip' => $newMasterIp,
            ':new_ns' => $newNsName,
            ':account' => $account,
            ':old_ip' => $oldMasterIp,
            ':old_ns' => $oldNsName,
        ]);

        return true;
    }

    // ---------------------------------------------------------------
    // Capability
    // ---------------------------------------------------------------

    public function isApiBackend(): bool
    {
        return false;
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function isDeadlockError(\Exception $e): bool
    {
        $code = $e->getCode();
        return $code == 1213 || $code == '40001'
            || strpos($e->getMessage(), '1213') !== false
            || strpos($e->getMessage(), 'Deadlock') !== false;
    }
}
