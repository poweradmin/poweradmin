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
use Poweradmin\Domain\Port\ApiStatusInterface;
use Poweradmin\Domain\Service\Consistency\ConsistencyReport;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Database\CanonicalZoneSql;

/**
 * Consistency checks for the API backend: zone and record state comes from the
 * PowerDNS API, ownership from the Poweradmin-native zones tables.
 *
 * Zones PowerDNS reports but Poweradmin has not synced yet carry id 0 and have no
 * local row; every per-zone check skips them until ZoneSyncService imports them.
 */
class ApiConsistencyChecks extends AbstractConsistencyChecks
{
    private bool $recordReadFailed = false;

    public function __construct(
        private readonly PDO $db,
        private readonly DnsBackendProviderInterface $backend,
        private readonly ApiStatusInterface $apiStatus,
        ZoneOwnerRepair $ownerRepair
    ) {
        parent::__construct($ownerRepair);
    }

    public function checkZonesHaveOwners(): array
    {
        return $this->ownerReport($this->backend->getZones());
    }

    public function checkZonesHaveCanonicalIds(): array
    {
        $stmt = $this->db->query(
            "SELECT id, zone_name, domain_id FROM zones
             WHERE (domain_id IS NULL OR domain_id = 0) AND zone_name IS NOT NULL
             ORDER BY id"
        );

        $stranded = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stranded[] = ['id' => (int)$row['id'], 'name' => $row['zone_name'], 'domain_id' => $row['domain_id']];
        }

        return ConsistencyReport::build($stranded, _('All zones have a canonical ID'), 'warning', _('%d zones found without a canonical ID'));
    }

    public function checkSlaveZonesHaveMasters(): array
    {
        return $this->masterReport($this->backend->getZones());
    }

    /** PowerDNS owns record-zone integrity in API mode, so orphaned records cannot exist. */
    public function checkRecordsBelongToZones(): array
    {
        return ConsistencyReport::allClear(_('All records belong to existing zones'));
    }

    public function checkDuplicateSOARecords(): array
    {
        return $this->duplicateSoaReport($this->backend->getZones());
    }

    public function checkZonesWithoutSOA(): array
    {
        return $this->missingSoaReport($this->backend->getZones());
    }

    /**
     * The zone list is fetched once and shared by every check, so one transient
     * failure cannot make one check pass while another fails. The provider swallows
     * transport errors into an empty list; an empty list paired with a recorded API
     * error means an outage, and so does a failed per-zone record read.
     */
    public function runAllChecks(): ?array
    {
        $zones = $this->backend->getZones();
        if ($zones === [] && $this->apiStatus->getLastError() !== null) {
            return null;
        }

        $this->recordReadFailed = false;
        $results = [
            'zones_have_owners' => $this->ownerReport($zones),
            'zones_have_canonical_ids' => $this->checkZonesHaveCanonicalIds(),
            'slave_zones_have_masters' => $this->masterReport($zones),
            'records_belong_to_zones' => $this->checkRecordsBelongToZones(),
            'duplicate_soa_records' => $this->duplicateSoaReport($zones),
            'zones_without_soa' => $this->missingSoaReport($zones),
        ];

        return $this->unlessARecordReadFailed($results);
    }

    public function fixZoneCanonicalId(int $zoneId): bool
    {
        if ($zoneId <= 0) {
            return false;
        }

        // Predicated on the broken state so a repeated or stale submission cannot rewrite a
        // healthy row, and so placeholder ownership rows are never self-referenced.
        $stmt = $this->db->prepare(
            "UPDATE zones SET domain_id = :did
             WHERE id = :id AND (domain_id IS NULL OR domain_id = 0) AND zone_name IS NOT NULL"
        );
        $stmt->bindValue(':did', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function fixAllZonesWithCanonicalIdIssue(): array
    {
        return ConsistencyReport::repairEach(
            ConsistencyReport::findingIds($this->checkZonesHaveCanonicalIds()),
            fn(int $zoneId): bool => $this->fixZoneCanonicalId($zoneId),
            'fixed'
        );
    }

    /** Deletes the DNS zone first so the ownership rows survive a failed API call. */
    public function deleteSlaveZone(int $zoneId): bool
    {
        $zoneName = $this->backend->getZoneNameById($zoneId) ?? '';
        if (!$this->backend->deleteZone($zoneId, $zoneName)) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :domain_id");
            $stmt->execute(['domain_id' => $zoneId]);

            $stmt = $this->db->prepare("DELETE FROM zones WHERE domain_id = :domain_id");
            $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function deleteOrphanedRecord(int $recordId): bool
    {
        return $this->backend->deleteRecord($recordId);
    }

    public function fixDuplicateSOA(int $zoneId): bool
    {
        $records = $this->backend->getRecordsByZoneId($zoneId, 'SOA');
        if (count($records) <= 1) {
            return true;
        }

        array_shift($records);
        foreach ($records as $record) {
            $recordId = (int)($record['id'] ?? 0);
            if ($recordId > 0) {
                $this->backend->deleteRecord($recordId);
            }
        }

        return true;
    }

    public function createDefaultSOA(int $zoneId): bool
    {
        $zoneName = $this->backend->getZoneNameById($zoneId);
        if ($zoneName === null || $zoneName === '') {
            return false;
        }

        $recordId = $this->backend->addRecordGetId($zoneId, $zoneName, 'SOA', ConsistencyReport::defaultSoaContent($zoneName), 86400, 0);

        return $recordId !== null;
    }

    /** @param list<array<string, mixed>> $zones */
    private function ownerReport(array $zones): array
    {
        $orphanedZones = [];
        foreach ($zones as $zone) {
            $zoneId = (int)($zone['id'] ?? 0);
            $zoneName = rtrim($zone['name'] ?? '', '.');
            if ($zoneId > 0 && !$this->hasOwnerOrGroup($zoneName)) {
                $orphanedZones[] = ['id' => $zoneId, 'name' => $zoneName, 'owner' => null];
            }
        }

        return ConsistencyReport::build($orphanedZones, _('All zones have owners'), 'warning', _('%d zones found without owners'));
    }

    /**
     * A zone may have several zones rows (one per direct owner) plus zones_groups
     * entries, all keyed by the canonical id of the row unique by zone_name.
     */
    private function hasOwnerOrGroup(string $zoneName): bool
    {
        $canonicalId = CanonicalZoneSql::canonicalIdColumn('c');
        $stmt = $this->db->prepare(
            "SELECT
                (SELECT COUNT(*) FROM zones z
                 WHERE (z.id = c.id OR z.domain_id = $canonicalId)
                   AND z.owner IS NOT NULL AND z.owner <> 0) AS owner_count,
                (SELECT COUNT(*) FROM zones_groups zg
                 WHERE zg.domain_id = $canonicalId) AS group_count
             FROM zones c
             WHERE c.zone_name = ? AND c.zone_name IS NOT NULL
             LIMIT 1"
        );
        $stmt->execute([$zoneName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && ((int)($row['owner_count'] ?? 0) > 0 || (int)($row['group_count'] ?? 0) > 0);
    }

    /** @param list<array<string, mixed>> $zones */
    private function masterReport(array $zones): array
    {
        $slavesWithoutMaster = [];
        foreach ($zones as $zone) {
            if (strtoupper($zone['type'] ?? '') !== 'SLAVE') {
                continue;
            }
            $master = $zone['master'] ?? '';
            if (empty($master) || $master === '0.0.0.0') {
                $slavesWithoutMaster[] = [
                    'id' => (int)($zone['id'] ?? 0),
                    'name' => rtrim($zone['name'] ?? '', '.'),
                    'master' => $master,
                ];
            }
        }

        return ConsistencyReport::build(
            $slavesWithoutMaster,
            _('All slave zones have master IP addresses'),
            'warning',
            _('%d slave zones found without master IP addresses')
        );
    }

    /** @param list<array<string, mixed>> $zones */
    private function duplicateSoaReport(array $zones): array
    {
        $duplicateSOA = [];
        foreach ($zones as $zone) {
            $zoneId = (int)($zone['id'] ?? 0);
            if ($zoneId <= 0) {
                continue;
            }
            $soaCount = count($this->soaRecords($zoneId));
            if ($soaCount > 1) {
                $duplicateSOA[] = ['zone_id' => $zoneId, 'zone_name' => rtrim($zone['name'] ?? '', '.'), 'soa_count' => $soaCount];
            }
        }

        return ConsistencyReport::build($duplicateSOA, _('No duplicate SOA records found'), 'error', _('%d zones have duplicate SOA records'));
    }

    /** @param list<array<string, mixed>> $zones */
    private function missingSoaReport(array $zones): array
    {
        $zonesWithoutSOA = [];
        foreach ($zones as $zone) {
            $kind = strtoupper($zone['type'] ?? '');
            $zoneId = (int)($zone['id'] ?? 0);
            if (($kind !== 'MASTER' && $kind !== 'NATIVE') || $zoneId <= 0) {
                continue;
            }
            if ($this->soaRecords($zoneId) === []) {
                $zonesWithoutSOA[] = ['id' => $zoneId, 'name' => rtrim($zone['name'] ?? '', '.'), 'type' => $kind];
            }
        }

        return ConsistencyReport::build($zonesWithoutSOA, _('All zones have SOA records'), 'error', _('%d zones found without SOA records'));
    }

    /**
     * The provider swallows a failed per-zone read into an empty list, but records
     * the failure and clears it on success; an error right after the call means this
     * read failed and the empty result must not be trusted as "no records".
     */
    private function soaRecords(int $zoneId): array
    {
        $records = $this->backend->getRecordsByZoneId($zoneId, 'SOA');
        if ($this->apiStatus->getLastError() !== null) {
            $this->recordReadFailed = true;
        }

        return $records;
    }

    /**
     * @param array<string, array{status: string, message: string, data: array}> $results
     * @return array<string, array{status: string, message: string, data: array}>|null
     */
    private function unlessARecordReadFailed(array $results): ?array
    {
        return $this->recordReadFailed ? null : $results;
    }
}
