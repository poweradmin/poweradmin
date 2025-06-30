<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Domain\Service;

use Exception;
use PDO;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;

class DatabaseConsistencyService
{
    private PDOCommon $db;
    private ConfigurationManager $config;
    private TableNameService $tableNameService;

    public function __construct(PDOCommon $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->tableNameService = new TableNameService($config);
    }


    /**
     * Check if all zones have an owner
     *
     * @return array{status: string, message: string, data: array}
     */
    public function checkZonesHaveOwners(): array
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->query("
            SELECT d.id, d.name, z.owner
            FROM $domains_table d
            LEFT JOIN zones z ON d.id = z.domain_id
            WHERE z.owner IS NULL OR z.owner = 0
        ");

        $orphanedZones = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $orphanedZones[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'owner' => $row['owner']
            ];
        }

        if (empty($orphanedZones)) {
            return [
                'status' => 'success',
                'message' => _('All zones have owners'),
                'data' => []
            ];
        }

        return [
            'status' => 'warning',
            'message' => sprintf(_('%d zones found without owners'), count($orphanedZones)),
            'data' => $orphanedZones
        ];
    }

    /**
     * Check if all slave zones have master IP addresses
     *
     * @return array{status: string, message: string, data: array}
     */
    public function checkSlaveZonesHaveMasters(): array
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->query("
            SELECT d.id, d.name, d.master
            FROM $domains_table d
            WHERE d.type = 'SLAVE'
            AND (d.master IS NULL OR d.master = '' OR d.master = '0.0.0.0')
        ");

        $slavesWithoutMaster = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $slavesWithoutMaster[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'master' => $row['master']
            ];
        }

        if (empty($slavesWithoutMaster)) {
            return [
                'status' => 'success',
                'message' => _('All slave zones have master IP addresses'),
                'data' => []
            ];
        }

        return [
            'status' => 'warning',
            'message' => sprintf(_('%d slave zones found without master IP addresses'), count($slavesWithoutMaster)),
            'data' => $slavesWithoutMaster
        ];
    }

    /**
     * Check if all records belong to existing zones
     *
     * @return array{status: string, message: string, data: array}
     */
    public function checkRecordsBelongToZones(): array
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->query("
            SELECT r.id, r.name, r.type, r.content, r.domain_id
            FROM $records_table r
            LEFT JOIN $domains_table d ON r.domain_id = d.id
            WHERE d.id IS NULL
        ");

        $orphanedRecords = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $orphanedRecords[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'type' => $row['type'],
                'content' => $row['content'],
                'domain_id' => $row['domain_id']
            ];
        }

        if (empty($orphanedRecords)) {
            return [
                'status' => 'success',
                'message' => _('All records belong to existing zones'),
                'data' => []
            ];
        }

        return [
            'status' => 'error',
            'message' => sprintf(_('%d orphaned records found'), count($orphanedRecords)),
            'data' => $orphanedRecords
        ];
    }

    /**
     * Check for duplicate SOA records
     *
     * @return array{status: string, message: string, data: array}
     */
    public function checkDuplicateSOARecords(): array
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $stmt = $this->db->query("
            SELECT domain_id, COUNT(*) as soa_count
            FROM $records_table
            WHERE type = 'SOA'
            GROUP BY domain_id
            HAVING COUNT(*) > 1
        ");

        $duplicateSOA = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Get zone name
            $zoneStmt = $this->db->prepare("SELECT name FROM $domains_table WHERE id = :zone_id");
            $zoneStmt->execute(['zone_id' => $row['domain_id']]);
            $zoneName = $zoneStmt->fetchColumn();

            $duplicateSOA[] = [
                'zone_id' => $row['domain_id'],
                'zone_name' => $zoneName,
                'soa_count' => $row['soa_count']
            ];
        }

        if (empty($duplicateSOA)) {
            return [
                'status' => 'success',
                'message' => _('No duplicate SOA records found'),
                'data' => []
            ];
        }

        return [
            'status' => 'error',
            'message' => sprintf(_('%d zones have duplicate SOA records'), count($duplicateSOA)),
            'data' => $duplicateSOA
        ];
    }

    /**
     * Check for zones without SOA records
     *
     * @return array{status: string, message: string, data: array}
     */
    public function checkZonesWithoutSOA(): array
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->query("
            SELECT d.id, d.name, d.type
            FROM $domains_table d
            LEFT JOIN $records_table r ON d.id = r.domain_id AND r.type = 'SOA'
            WHERE r.id IS NULL
            AND d.type IN ('MASTER', 'NATIVE')
        ");

        $zonesWithoutSOA = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $zonesWithoutSOA[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'type' => $row['type']
            ];
        }

        if (empty($zonesWithoutSOA)) {
            return [
                'status' => 'success',
                'message' => _('All zones have SOA records'),
                'data' => []
            ];
        }

        return [
            'status' => 'error',
            'message' => sprintf(_('%d zones found without SOA records'), count($zonesWithoutSOA)),
            'data' => $zonesWithoutSOA
        ];
    }

    /**
     * Run all consistency checks
     *
     * @return array<string, array{status: string, message: string, data: array}>
     */
    public function runAllChecks(): array
    {
        return [
            'zones_have_owners' => $this->checkZonesHaveOwners(),
            'slave_zones_have_masters' => $this->checkSlaveZonesHaveMasters(),
            'records_belong_to_zones' => $this->checkRecordsBelongToZones(),
            'duplicate_soa_records' => $this->checkDuplicateSOARecords(),
            'zones_without_soa' => $this->checkZonesWithoutSOA()
        ];
    }

    /**
     * Fix zone without owner by assigning the current user as owner
     *
     * @param int $zoneId The zone ID to fix
     * @param int $currentUserId The current user ID from UserContextService
     * @return bool Success status
     */
    public function fixZoneWithoutOwner(int $zoneId, int $currentUserId): bool
    {
        // Check if zone entry exists
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM zones WHERE domain_id = :domain_id");
        $stmt->execute(['domain_id' => $zoneId]);
        $exists = $stmt->fetchColumn() > 0;

        if ($exists) {
            // Update existing entry
            $stmt = $this->db->prepare("UPDATE zones SET owner = :owner WHERE domain_id = :domain_id");
        } else {
            // Insert new entry
            $stmt = $this->db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (:domain_id, :owner, 0)");
        }

        return $stmt->execute(['domain_id' => $zoneId, 'owner' => $currentUserId]);
    }

    /**
     * Delete a slave zone
     *
     * @param int $zoneId The zone ID to delete
     * @return bool Success status
     */
    public function deleteSlaveZone(int $zoneId): bool
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        $this->db->beginTransaction();
        try {
            // Delete records
            $stmt = $this->db->prepare("DELETE FROM $records_table WHERE domain_id = :domain_id");
            $stmt->execute(['domain_id' => $zoneId]);

            // Delete zone ownership
            $stmt = $this->db->prepare("DELETE FROM zones WHERE domain_id = :domain_id");
            $stmt->execute(['domain_id' => $zoneId]);

            // Delete domain
            $stmt = $this->db->prepare("DELETE FROM $domains_table WHERE id = :id");
            $stmt->execute(['id' => $zoneId]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Delete an orphaned record
     *
     * @param int $recordId The record ID to delete
     * @return bool Success status
     */
    public function deleteOrphanedRecord(int $recordId): bool
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("DELETE FROM $records_table WHERE id = :id");
        return $stmt->execute(['id' => $recordId]);
    }

    /**
     * Fix duplicate SOA records by keeping only the first one
     *
     * @param int $zoneId The zone ID with duplicate SOA records
     * @return bool Success status
     */
    public function fixDuplicateSOA(int $zoneId): bool
    {
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        $this->db->beginTransaction();
        try {
            // Get all SOA records for this zone, ordered by ID
            $stmt = $this->db->prepare("SELECT id FROM $records_table WHERE domain_id = :zone_id AND type = 'SOA' ORDER BY id ASC");
            $stmt->execute(['zone_id' => $zoneId]);
            $soaRecords = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($soaRecords) <= 1) {
                $this->db->commit();
                return true;
            }

            // Keep the first SOA record, delete the rest
            array_shift($soaRecords); // Remove the first ID from the array
            $placeholders = implode(',', array_fill(0, count($soaRecords), '?'));
            $stmt = $this->db->prepare("DELETE FROM $records_table WHERE id IN ($placeholders)");
            $stmt->execute($soaRecords);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Create a default SOA record for a zone
     *
     * @param int $zoneId The zone ID to create SOA for
     * @return bool Success status
     */
    public function createDefaultSOA(int $zoneId): bool
    {
        $domains_table = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $records_table = $this->tableNameService->getTable(PdnsTable::RECORDS);

        // Get zone name
        $stmt = $this->db->prepare("SELECT name FROM $domains_table WHERE id = :id");
        $stmt->execute(['id' => $zoneId]);
        $zoneName = $stmt->fetchColumn();

        if (!$zoneName) {
            return false;
        }

        // Get default nameservers from config or use defaults
        $primaryNs = 'ns1.' . $zoneName;
        $hostmaster = 'hostmaster.' . $zoneName;
        $serial = date('Ymd') . '01';

        // Create SOA content
        $soaContent = sprintf(
            '%s %s %s 28800 7200 604800 86400',
            $primaryNs,
            $hostmaster,
            $serial
        );

        // Insert SOA record
        $stmt = $this->db->prepare("
            INSERT INTO $records_table (domain_id, name, type, content, ttl, prio, disabled) 
            VALUES (:domain_id, :name, 'SOA', :content, 86400, 0, 0)
        ");

        return $stmt->execute([
            'domain_id' => $zoneId,
            'name' => $zoneName,
            'content' => $soaContent
        ]);
    }
}
