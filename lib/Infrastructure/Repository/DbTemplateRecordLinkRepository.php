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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\TemplateRecordLinkRepositoryInterface;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Database\PdnsTable;
use Poweradmin\Domain\Database\TableNameService;

/**
 * SQL persistence for records_zone_templ (numeric ids) and records_zone_templ_api (encoded ids).
 */
class DbTemplateRecordLinkRepository implements TemplateRecordLinkRepositoryInterface
{
    private const RECORD_COLUMNS = 'id, name, type, content, ttl, prio, disabled';

    private PDO $db;
    private BackendCapabilitiesInterface $backend;
    private string $recordsTable;

    public function __construct(PDO $db, ConfigurationInterface $config, BackendCapabilitiesInterface $backend)
    {
        $this->db = $db;
        $this->backend = $backend;
        $this->recordsTable = (new TableNameService($config))->getTable(PdnsTable::RECORDS);
    }

    public function linkRecord(int $zoneId, int|string $recordId, int $templateId): void
    {
        if ($this->backend->recordIdsAreNumeric()) {
            $stmt = $this->db->prepare("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (:zone_id, :record_id, :zone_templ_id)");
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->bindValue(':record_id', (int) $recordId, PDO::PARAM_INT);
            $stmt->bindValue(':zone_templ_id', $templateId, PDO::PARAM_INT);
            $stmt->execute();
            return;
        }

        $stmt = $this->db->prepare("INSERT INTO records_zone_templ_api (domain_id, record_id, zone_templ_id) VALUES (:zone_id, :record_id, :zone_templ_id)");
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':record_id', (string) $recordId, PDO::PARAM_STR);
        $stmt->bindValue(':zone_templ_id', $templateId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function removeLinkedRecords(int $zoneId, int $templateId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.id, r.name, r.type, r.content, r.ttl, r.prio, r.disabled
             FROM {$this->recordsTable} r
             INNER JOIN records_zone_templ rzt ON r.id = rzt.record_id
             WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_templ_id"
        );
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_templ_id', $templateId, PDO::PARAM_INT);
        $stmt->execute();
        $removed = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $this->deleteRecordsByIds(array_map(static fn(array $row): int => (int) $row['id'], $removed));

        // Clear the links even when the records were already gone, otherwise a
        // reused SQLite rowid could later resolve a stale link to an unrelated record.
        $stmt = $this->db->prepare("DELETE FROM records_zone_templ WHERE domain_id = :zone_id AND zone_templ_id = :zone_templ_id");
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_templ_id', $templateId, PDO::PARAM_INT);
        $stmt->execute();

        return $removed;
    }

    public function removeSoaRecords(int $zoneId): array
    {
        $stmt = $this->db->prepare("SELECT " . self::RECORD_COLUMNS . " FROM {$this->recordsTable} WHERE domain_id = :zone_id AND type = 'SOA'");
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $removed = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare("DELETE FROM {$this->recordsTable} WHERE domain_id = :zone_id AND type = 'SOA'");
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return $removed;
    }

    public function listEncodedLinks(int $zoneId, int $templateId): array
    {
        $stmt = $this->db->prepare("SELECT id, record_id FROM records_zone_templ_api WHERE domain_id = :zone_id AND zone_templ_id = :zone_templ_id");
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_templ_id', $templateId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function removeEncodedLinks(array $linkIds): void
    {
        if ($linkIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($linkIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM records_zone_templ_api WHERE id IN ($placeholders)");
        foreach (array_values($linkIds) as $i => $id) {
            $stmt->bindValue($i + 1, (int) $id, PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    /**
     * @param int[] $ids
     */
    private function deleteRecordsByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("DELETE FROM {$this->recordsTable} WHERE id IN ($placeholders)");
        foreach (array_values($ids) as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();
    }
}
