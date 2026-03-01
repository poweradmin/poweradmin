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
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\TableNameService;

/**
 * SQL-based DNS backend provider.
 *
 * Performs DNS data operations directly against the PowerDNS database tables.
 * This is the default backend and preserves the existing behavior.
 */
class SqlDnsBackendProvider implements DnsBackendProvider
{
    private PDOCommon $db;
    private ConfigurationInterface $config;
    private TableNameService $tableNameService;

    public function __construct(PDOCommon $db, ConfigurationInterface $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->tableNameService = new TableNameService($config);
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

    public function addRecordGetId(int $domainId, string $name, string $type, string $content, int $ttl, int $prio): ?int
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

    public function editRecord(int $recordId, string $name, string $type, string $content, int $ttl, int $prio, int $disabled): bool
    {
        $recordsTable = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("UPDATE $recordsTable SET name = ?, type = ?, content = ?, ttl = ?, prio = ?, disabled = ? WHERE id = ?");
        $stmt->execute([$name, $type, $content, $ttl, $prio, $disabled, $recordId]);

        return true;
    }

    public function deleteRecord(int $recordId): bool
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
}
