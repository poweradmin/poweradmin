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

namespace Poweradmin\Infrastructure\Service\Consistency;

use Exception;
use PDO;
use Poweradmin\Domain\Service\Consistency\ConsistencyReport;
use Poweradmin\Domain\Database\PdnsTable;
use Poweradmin\Domain\Database\TableNameService;

/**
 * Consistency checks against the PowerDNS tables in the local database.
 */
class SqlConsistencyChecks extends AbstractConsistencyChecks
{
    private string $domainsTable;
    private string $recordsTable;

    public function __construct(
        private readonly PDO $db,
        TableNameService $tableNameService,
        ZoneOwnerRepair $ownerRepair
    ) {
        parent::__construct($ownerRepair);
        $this->domainsTable = $tableNameService->getTable(PdnsTable::DOMAINS);
        $this->recordsTable = $tableNameService->getTable(PdnsTable::RECORDS);
    }

    public function checkZonesHaveOwners(): array
    {
        $stmt = $this->db->query("
            SELECT d.id, d.name
            FROM {$this->domainsTable} d
            WHERE NOT EXISTS (
                SELECT 1 FROM zones z
                WHERE z.domain_id = d.id AND z.owner IS NOT NULL AND z.owner <> 0
            )
              AND NOT EXISTS (
                SELECT 1 FROM zones_groups zg WHERE zg.domain_id = d.id
            )
        ");

        $orphanedZones = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $orphanedZones[] = ['id' => $row['id'], 'name' => $row['name'], 'owner' => null];
        }

        return ConsistencyReport::build($orphanedZones, _('All zones have owners'), 'warning', _('%d zones found without owners'));
    }

    /**
     * SQL mode stores a domains foreign key in zones.domain_id, where the row's own id is
     * not a valid substitute, so there is nothing this check may safely report or repair.
     */
    public function checkZonesHaveCanonicalIds(): array
    {
        return ConsistencyReport::allClear(_('All zones have a canonical ID'));
    }

    public function checkSlaveZonesHaveMasters(): array
    {
        $stmt = $this->db->query("
            SELECT d.id, d.name, d.master
            FROM {$this->domainsTable} d
            WHERE d.type = 'SLAVE'
            AND (d.master IS NULL OR d.master = '' OR d.master = '0.0.0.0')
        ");

        $slavesWithoutMaster = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $slavesWithoutMaster[] = ['id' => $row['id'], 'name' => $row['name'], 'master' => $row['master']];
        }

        return ConsistencyReport::build(
            $slavesWithoutMaster,
            _('All slave zones have master IP addresses'),
            'warning',
            _('%d slave zones found without master IP addresses')
        );
    }

    public function checkRecordsBelongToZones(): array
    {
        $stmt = $this->db->query("
            SELECT r.id, r.name, r.type, r.content, r.domain_id
            FROM {$this->recordsTable} r
            LEFT JOIN {$this->domainsTable} d ON r.domain_id = d.id
            WHERE d.id IS NULL
        ");

        $orphanedRecords = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $orphanedRecords[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'type' => $row['type'],
                'content' => $row['content'],
                'domain_id' => $row['domain_id'],
            ];
        }

        return ConsistencyReport::build($orphanedRecords, _('All records belong to existing zones'), 'error', _('%d orphaned records found'));
    }

    public function checkDuplicateSOARecords(): array
    {
        $stmt = $this->db->query("
            SELECT domain_id, COUNT(*) as soa_count
            FROM {$this->recordsTable}
            WHERE type = 'SOA'
            GROUP BY domain_id
            HAVING COUNT(*) > 1
        ");

        $duplicateSOA = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $duplicateSOA[] = [
                'zone_id' => $row['domain_id'],
                'zone_name' => $this->zoneName((int)$row['domain_id']),
                'soa_count' => $row['soa_count'],
            ];
        }

        return ConsistencyReport::build($duplicateSOA, _('No duplicate SOA records found'), 'error', _('%d zones have duplicate SOA records'));
    }

    public function checkZonesWithoutSOA(): array
    {
        $stmt = $this->db->query("
            SELECT d.id, d.name, d.type
            FROM {$this->domainsTable} d
            LEFT JOIN {$this->recordsTable} r ON d.id = r.domain_id AND r.type = 'SOA'
            WHERE r.id IS NULL
            AND d.type IN ('MASTER', 'NATIVE')
        ");

        $zonesWithoutSOA = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $zonesWithoutSOA[] = ['id' => $row['id'], 'name' => $row['name'], 'type' => $row['type']];
        }

        return ConsistencyReport::build($zonesWithoutSOA, _('All zones have SOA records'), 'error', _('%d zones found without SOA records'));
    }

    public function runAllChecks(): ?array
    {
        return [
            'zones_have_owners' => $this->checkZonesHaveOwners(),
            'zones_have_canonical_ids' => $this->checkZonesHaveCanonicalIds(),
            'slave_zones_have_masters' => $this->checkSlaveZonesHaveMasters(),
            'records_belong_to_zones' => $this->checkRecordsBelongToZones(),
            'duplicate_soa_records' => $this->checkDuplicateSOARecords(),
            'zones_without_soa' => $this->checkZonesWithoutSOA(),
        ];
    }

    public function fixZoneCanonicalId(int $zoneId): bool
    {
        return false;
    }

    public function fixAllZonesWithCanonicalIdIssue(): array
    {
        return ['fixed' => 0, 'failed' => 0];
    }

    public function deleteSlaveZone(int $zoneId): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM {$this->recordsTable} WHERE domain_id = :domain_id");
            $stmt->execute(['domain_id' => $zoneId]);

            $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :domain_id");
            $stmt->execute(['domain_id' => $zoneId]);

            $stmt = $this->db->prepare("DELETE FROM zones WHERE domain_id = :domain_id");
            $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();

            $stmt = $this->db->prepare("DELETE FROM {$this->domainsTable} WHERE id = :id");
            $stmt->execute(['id' => $zoneId]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function deleteOrphanedRecord(int $recordId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->recordsTable} WHERE id = :id");
        return $stmt->execute(['id' => $recordId]);
    }

    public function fixDuplicateSOA(int $zoneId): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT id FROM {$this->recordsTable} WHERE domain_id = :zone_id AND type = 'SOA' ORDER BY id ASC");
            $stmt->execute(['zone_id' => $zoneId]);
            $soaRecords = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($soaRecords) <= 1) {
                $this->db->commit();
                return true;
            }

            array_shift($soaRecords);
            $placeholders = implode(',', array_fill(0, count($soaRecords), '?'));
            $stmt = $this->db->prepare("DELETE FROM {$this->recordsTable} WHERE id IN ($placeholders)");
            $stmt->execute($soaRecords);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function createDefaultSOA(int $zoneId): bool
    {
        $zoneName = $this->zoneName($zoneId);
        if ($zoneName === null) {
            return false;
        }

        $stmt = $this->db->prepare("
            INSERT INTO {$this->recordsTable} (domain_id, name, type, content, ttl, prio, disabled)
            VALUES (:domain_id, :name, 'SOA', :content, 86400, 0, 0)
        ");

        return $stmt->execute([
            'domain_id' => $zoneId,
            'name' => $zoneName,
            'content' => ConsistencyReport::defaultSoaContent($zoneName),
        ]);
    }

    private function zoneName(int $zoneId): ?string
    {
        $stmt = $this->db->prepare("SELECT name FROM {$this->domainsTable} WHERE id = :id");
        $stmt->execute(['id' => $zoneId]);
        $name = $stmt->fetchColumn();

        return $name === false || $name === null || $name === '' ? null : (string)$name;
    }
}
