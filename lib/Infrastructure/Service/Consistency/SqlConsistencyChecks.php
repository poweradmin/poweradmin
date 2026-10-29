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

use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Database\SharedZoneIds;
use Poweradmin\Infrastructure\Database\SqlZoneNames;
use Poweradmin\Domain\Port\TransactionInterface;
use Exception;
use PDO;
use Poweradmin\Domain\Database\DbCompat;
use Poweradmin\Domain\Service\Consistency\ConsistencyReport;
use Poweradmin\Domain\Service\Dns\DefaultSoaBuilder;
use Poweradmin\Domain\Database\PdnsTable;
use Poweradmin\Domain\Database\TableNameService;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Infrastructure\Repository\AccountOwnerLookup;

/**
 * Consistency checks against the PowerDNS tables in the local database.
 */
class SqlConsistencyChecks extends AbstractConsistencyChecks
{
    private string $domainsTable;
    private ?TransactionInterface $transactionPort = null;
    private string $recordsTable;
    private string $commentsTable;

    public function __construct(
        private readonly PDO $db,
        TableNameService $tableNameService,
        ZoneOwnerRepair $ownerRepair,
        DefaultSoaBuilder $defaultSoa,
        ?AccountOwnerLookup $accountOwners = null,
        ?ZoneChangeRequestRepositoryInterface $changeRequests = null
    ) {
        parent::__construct($ownerRepair, $defaultSoa, $accountOwners, $changeRequests);
        $this->domainsTable = $tableNameService->getTable(PdnsTable::DOMAINS);
        $this->recordsTable = $tableNameService->getTable(PdnsTable::RECORDS);
        $this->commentsTable = $tableNameService->getTable(PdnsTable::COMMENTS);
    }

    protected function zoneAccount(int $zoneId): ?string
    {
        $stmt = $this->db->prepare("SELECT account FROM {$this->domainsTable} WHERE id = :id");
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $account = $stmt->fetchColumn();

        return is_string($account) ? $account : null;
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

    public function checkZonesHaveNames(): array
    {
        // Only zones with owners or grants keyed by their id have something to lose
        $stmt = $this->db->query(
            "SELECT d.id, d.name FROM $this->domainsTable d
             WHERE NOT EXISTS (SELECT 1 FROM zones n WHERE n.domain_id = d.id AND n.zone_name = d.name)
               AND (EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id)
                    OR EXISTS (SELECT 1 FROM zones_groups g WHERE g.domain_id = d.id)
                    OR EXISTS (SELECT 1 FROM api_key_zones k WHERE k.zone_id = d.id))
             ORDER BY d.name"
        );

        $unnamed = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $unnamed[] = ['id' => (int)$row['id'], 'name' => $row['name']];
        }

        return ConsistencyReport::build(
            $unnamed,
            _('All zones are ready for the API backend'),
            'warning',
            _('%d zones have no stored zone name; switching to the API backend would add them again without owners')
        );
    }

    /** SQL mode keys every zone by its unique domains.id, so no id can be shared. */
    public function checkSharedZoneIds(): array
    {
        return ConsistencyReport::allClear(_('No zone ID is shared by two zones'));
    }

    /** SQL mode keys zones by domains.id only, so no group assignment holds a row id. */
    public function checkGroupGrantsOnRowIds(): array
    {
        return ConsistencyReport::allClear(_('All group assignments use canonical zone IDs'));
    }

    public function checkApiKeyScopesNameZones(): array
    {
        $stmt = $this->db->query(
            "SELECT k.zone_id, a.name FROM api_key_zones k JOIN api_keys a ON a.id = k.api_key_id
             WHERE k.zone_id <> 0 AND NOT EXISTS (SELECT 1 FROM $this->domainsTable d WHERE d.id = k.zone_id)
             ORDER BY k.zone_id, a.name"
        );
        $keysByZone = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $keysByZone[(int)$row['zone_id']][] = (string)$row['name'];
        }
        $findings = [];
        foreach ($keysByZone as $zoneId => $keys) {
            $findings[] = ['id' => $zoneId, 'keys' => implode(', ', $keys)];
        }

        return ConsistencyReport::build(
            $findings,
            _('All API key zone restrictions name an existing zone'),
            'warning',
            _('%d deleted zones are still named by API key restrictions')
        );
    }

    public function checkZoneNamesDifferingInCase(): array
    {
        $stmt = $this->db->query(
            "SELECT d.id, d.name FROM {$this->domainsTable} d
             WHERE LOWER(d.name) IN (SELECT LOWER(n.name) FROM {$this->domainsTable} n GROUP BY LOWER(n.name) HAVING COUNT(*) > 1)
             ORDER BY LOWER(d.name), d.name"
        );

        return ConsistencyReport::build(
            $this->groupByLowerName($stmt->fetchAll(PDO::FETCH_ASSOC)),
            _('No two zones differ only in letter case'),
            'warning',
            _('%d zone names are used in more than one letter case')
        );
    }

    public function checkRecordNamesInLowercase(): array
    {
        $stmt = $this->db->query(
            "SELECT r.id, r.domain_id, r.name, r.type, d.name AS zone_name
             FROM {$this->recordsTable} r
             LEFT JOIN {$this->domainsTable} d ON d.id = r.domain_id
             WHERE {$this->mixedCaseName('r.name')}
             ORDER BY r.domain_id, r.name, r.type"
        );

        $findings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $findings[] = [
                'id' => (int)$row['id'],
                'domain_id' => (int)$row['domain_id'],
                'zone_name' => $row['zone_name'],
                'name' => $row['name'],
                'type' => $row['type'],
            ];
        }

        return ConsistencyReport::build(
            $findings,
            _('All record names are stored in lowercase'),
            'warning',
            _('%d records have uppercase letters in their name')
        );
    }

    /**
     * True for a name holding uppercase letters. MySQL compares through a case-insensitive
     * collation, so its side is compared byte for byte.
     */
    private function mixedCaseName(string $column): string
    {
        $driver = (string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        return DbCompat::binaryCompare($driver, $column) . " <> LOWER($column)";
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
            'zones_have_names' => $this->checkZonesHaveNames(),
            'shared_zone_ids' => $this->checkSharedZoneIds(),
            'group_grants_on_row_ids' => $this->checkGroupGrantsOnRowIds(),
            'api_key_scopes_name_zones' => $this->checkApiKeyScopesNameZones(),
            'zone_names_differing_in_case' => $this->checkZoneNamesDifferingInCase(),
            'record_names_in_lowercase' => $this->checkRecordNamesInLowercase(),
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

    public function fixApiKeyScopesOnMissingZone(int $zoneId): bool
    {
        if ($zoneId <= 0) {
            return false;
        }
        // Re-read so a zone created with this id since the check keeps the keys restricted to it
        $stmt = $this->db->prepare("SELECT 1 FROM $this->domainsTable WHERE id = :id");
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetchColumn() !== false) {
            return false;
        }

        return SharedZoneIds::pointApiKeyScopesAtNoZone($this->db, $zoneId) !== [];
    }

    public function fixAllApiKeyScopesOnMissingZones(): array
    {
        return ConsistencyReport::repairEach(
            ConsistencyReport::findingIds($this->checkApiKeyScopesNameZones()),
            fn(int $zoneId): bool => $this->fixApiKeyScopesOnMissingZone($zoneId),
            'fixed'
        );
    }

    public function fixZoneName(int $zoneId): bool
    {
        SqlZoneNames::ensureNamed($this->db, $this->domainsTable, $zoneId, true);

        $stmt = $this->db->prepare(
            "SELECT 1 FROM zones n JOIN $this->domainsTable d ON d.id = n.domain_id
             WHERE n.domain_id = :id AND n.zone_name = d.name"
        );
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public function fixAllZonesWithoutName(): array
    {
        return ConsistencyReport::repairEach(
            ConsistencyReport::findingIds($this->checkZonesHaveNames()),
            fn(int $zoneId): bool => $this->fixZoneName($zoneId),
            'fixed'
        );
    }

    /**
     * Renames the record to its lowercase name. When that name already holds the same
     * record the mixed-case copy is deleted instead. A rename that would leave two
     * versions of one record, or a CNAME next to other data, is refused.
     */
    public function fixRecordNameCase(int $recordId): bool
    {
        // LOWER() in SQL rather than strtolower(), so the checks look at the name the UPDATE writes
        $stmt = $this->db->prepare(
            "SELECT domain_id, name, LOWER(name) AS lower_name, type, content, prio, disabled
             FROM {$this->recordsTable} WHERE id = :id AND {$this->mixedCaseName('name')}"
        );
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($record === false) {
            return false;
        }

        $domainId = (int)$record['domain_id'];
        $lowerName = (string)$record['lower_name'];
        $resolution = $this->caseRepairFor($record, $this->recordsAtName($domainId, $lowerName, $recordId));
        if ($resolution === 'refuse') {
            return false;
        }

        $this->transaction()->begin();
        try {
            if (is_int($resolution)) {
                $this->replaceWithTwin($recordId, $resolution);
            } else {
                $stmt = $this->db->prepare("UPDATE {$this->recordsTable} SET name = :lower_name WHERE id = :id");
                $stmt->bindValue(':lower_name', $lowerName);
                $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
                $stmt->execute();
            }
            $this->lowercaseComments($domainId, (string)$record['name'], $lowerName, (string)$record['type']);

            $this->transaction()->commit();
            return true;
        } catch (Exception $e) {
            $this->transaction()->rollBack();
            return false;
        }
    }

    /**
     * The id of an identical record already at the lowercase name (delete this copy),
     * 'refuse' when the rename would leave two versions of one record or a CNAME next to
     * other data, otherwise 'rename'. Empty non-terminals only ever pair with each other.
     *
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $atLowerName
     */
    private function caseRepairFor(array $record, array $atLowerName): int|string
    {
        $isEnt = static fn(array $row): bool => (string)$row['type'] === '';
        if ($isEnt($record)) {
            $ents = array_values(array_filter($atLowerName, $isEnt));
            return $ents === [] ? 'rename' : (int)$ents[0]['id'];
        }
        $atLowerName = array_values(array_filter($atLowerName, static fn(array $row): bool => !$isEnt($row)));

        $key = static fn(array $row): string => implode("\0", [$row['type'], $row['content']]);
        $full = static fn(array $row): string => $key($row) . "\0" . (int)$row['prio'] . "\0" . DbCompat::boolFromDb($row['disabled']);
        foreach ($atLowerName as $row) {
            if ($full($row) === $full($record)) {
                return (int)$row['id'];
            }
        }
        if (in_array($key($record), array_map($key, $atLowerName), true)) {
            return 'refuse';
        }
        $types = array_column($atLowerName, 'type');
        $cnameConflict = $record['type'] === 'CNAME' ? $types !== [] : in_array('CNAME', $types, true);

        return $cnameConflict ? 'refuse' : 'rename';
    }

    /**
     * Other records of the zone stored under exactly this lowercase name, empty
     * non-terminals included.
     *
     * @return list<array<string, mixed>>
     */
    private function recordsAtName(int $domainId, string $lowerName, int $exceptId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, type, content, prio, disabled FROM {$this->recordsTable}
             WHERE domain_id = :domain_id AND {$this->exactName('name')} = :name AND id <> :id
             ORDER BY id"
        );
        $stmt->bindValue(':domain_id', $domainId, PDO::PARAM_INT);
        $stmt->bindValue(':name', $lowerName);
        $stmt->bindValue(':id', $exceptId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function exactName(string $column): string
    {
        return DbCompat::binaryCompare((string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME), $column);
    }

    /**
     * Deletes the copy and its template link. Its comment moves to the surviving twin
     * when that has none, and goes with the copy otherwise. Link record ids are strings,
     * as API-mode ids are.
     */
    private function replaceWithTwin(int $recordId, int $twinId): void
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM record_comment_links WHERE record_id = :id");
        $stmt->bindValue(':id', (string)$twinId);
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $stmt = $this->db->prepare("UPDATE record_comment_links SET record_id = :twin WHERE record_id = :id");
            $stmt->bindValue(':twin', (string)$twinId);
            $stmt->bindValue(':id', (string)$recordId);
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("SELECT comment_id FROM record_comment_links WHERE record_id = :id");
            $stmt->bindValue(':id', (string)$recordId);
            $stmt->execute();
            foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $commentId) {
                foreach (["DELETE FROM record_comment_links WHERE comment_id = :id", "DELETE FROM {$this->commentsTable} WHERE id = :id"] as $sql) {
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindValue(':id', $commentId, PDO::PARAM_INT);
                    $stmt->execute();
                }
            }
        }

        $stmt = $this->db->prepare("DELETE FROM records_zone_templ WHERE record_id = :id");
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $stmt = $this->db->prepare("DELETE FROM {$this->recordsTable} WHERE id = :id");
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Comments are keyed by zone, name and type, so they follow the record. Links point
     * at comment ids, so they stay valid.
     */
    private function lowercaseComments(int $domainId, string $name, string $lowerName, string $type): void
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->commentsTable} SET name = :lower_name
             WHERE domain_id = :domain_id AND {$this->exactName('name')} = :name AND type = :type"
        );
        $stmt->bindValue(':lower_name', $lowerName);
        $stmt->bindValue(':domain_id', $domainId, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':type', $type);
        $stmt->execute();
    }

    public function fixAllRecordNamesCase(): array
    {
        return ConsistencyReport::repairEach(
            ConsistencyReport::findingIds($this->checkRecordNamesInLowercase()),
            fn(int $recordId): bool => $this->fixRecordNameCase($recordId),
            'fixed'
        );
    }

    public function deleteSlaveZone(int $zoneId): bool
    {
        $this->transaction()->begin();
        try {
            SharedZoneIds::pointApiKeyScopesAtNoZone($this->db, $zoneId);
            $stmt = $this->db->prepare("DELETE FROM {$this->recordsTable} WHERE domain_id = :domain_id");
            $stmt->execute(['domain_id' => $zoneId]);

            $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :domain_id");
            $stmt->execute(['domain_id' => $zoneId]);

            $stmt = $this->db->prepare("DELETE FROM zones WHERE domain_id = :domain_id");
            $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();

            $stmt = $this->db->prepare("DELETE FROM {$this->domainsTable} WHERE id = :id");
            $stmt->execute(['id' => $zoneId]);

            $this->transaction()->commit();
            return true;
        } catch (Exception $e) {
            $this->transaction()->rollBack();
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
        $this->transaction()->begin();
        try {
            $stmt = $this->db->prepare("SELECT id FROM {$this->recordsTable} WHERE domain_id = :zone_id AND type = 'SOA' ORDER BY id ASC");
            $stmt->execute(['zone_id' => $zoneId]);
            $soaRecords = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($soaRecords) <= 1) {
                $this->transaction()->commit();
                return true;
            }

            array_shift($soaRecords);
            $placeholders = implode(',', array_fill(0, count($soaRecords), '?'));
            $stmt = $this->db->prepare("DELETE FROM {$this->recordsTable} WHERE id IN ($placeholders)");
            $stmt->execute($soaRecords);

            $this->transaction()->commit();
            return true;
        } catch (Exception $e) {
            $this->transaction()->rollBack();
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
            VALUES (:domain_id, :name, 'SOA', :content, :ttl, 0, 0)
        ");

        return $stmt->execute([
            'domain_id' => $zoneId,
            'name' => strtolower($zoneName),
            'content' => $this->defaultSoa->content(),
            'ttl' => $this->defaultSoa->ttl(),
        ]);
    }

    private function zoneName(int $zoneId): ?string
    {
        $stmt = $this->db->prepare("SELECT name FROM {$this->domainsTable} WHERE id = :id");
        $stmt->execute(['id' => $zoneId]);
        $name = $stmt->fetchColumn();

        return $name === false || $name === null || $name === '' ? null : (string)$name;
    }

    /**
     * Transactions go through the port: on SQLite one can be open without PDO
     * knowing, and opening a second one on the same handle then fails.
     */
    private function transaction(): TransactionInterface
    {
        return $this->transactionPort ??= new PdoTransaction($this->db);
    }
}
