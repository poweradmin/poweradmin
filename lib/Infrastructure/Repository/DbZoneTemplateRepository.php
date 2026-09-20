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

use Exception;
use LogicException;
use PDO;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\Constants;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Infrastructure\Database\CanonicalZoneSql;
use Poweradmin\Infrastructure\Database\DbCompat;

/**
 * SQL persistence for zone templates in zone_templ and their records in zone_templ_records.
 */
class DbZoneTemplateRepository implements ZoneTemplateRepositoryInterface
{
    /**
     * Default SOA record stamped into a template that carries none.
     */
    private const DEFAULT_SOA_NAME = '[ZONE]';
    private const DEFAULT_SOA_CONTENT = '[NS1] [HOSTMASTER] [SERIAL] 28800 7200 604800 86400';

    private object $db;

    /**
     * Read-only lookups never touch configuration, so callers that only read
     * (the static accessors on the ZoneTemplate model) may omit it.
     */
    private ?ConfigurationInterface $config;
    private ?DnsFormatter $dnsFormatter = null;

    public function __construct(object $db, ?ConfigurationInterface $config = null)
    {
        $this->db = $db;
        $this->config = $config;
    }

    private function config(): ConfigurationInterface
    {
        if ($this->config === null) {
            throw new LogicException('DbZoneTemplateRepository was built without configuration; this operation needs it.');
        }

        return $this->config;
    }

    private function dnsFormatter(): DnsFormatter
    {
        return $this->dnsFormatter ??= new DnsFormatter($this->config());
    }

    private function dbType(): string
    {
        return (string) $this->config()->get('database', 'type');
    }

    /**
     * List zone templates visible to the given user
     *
     * @param int|null $userId User ID (null for all)
     * @param bool $isUeberuser Whether user is an ueberuser
     * @return array List of zone templates
     */
    public function listZoneTemplates(?int $userId, bool $isUeberuser): array
    {
        $query = "SELECT zt.id, zt.name, zt.descr, zt.owner, zt.created_by, zt.is_default,
                      owner_user.username as owner_username,
                      owner_user.fullname as owner_fullname,
                      creator_user.username as creator_username,
                      creator_user.fullname as creator_fullname,
                      COUNT(z.zone_templ_id) as zones_linked
                FROM zone_templ zt
                LEFT JOIN users owner_user ON zt.owner = owner_user.id
                LEFT JOIN users creator_user ON zt.created_by = creator_user.id
                LEFT JOIN zones z ON zt.id = z.zone_templ_id";
        $params = [];

        if (!$isUeberuser && $userId !== null) {
            $query .= " WHERE zt.owner = :userid OR zt.owner = 0";
            $params[':userid'] = $userId;
        }

        $query .= " GROUP BY zt.id, zt.name, zt.descr, zt.owner, zt.created_by, zt.is_default,
                           owner_user.username, owner_user.fullname,
                           creator_user.username, creator_user.fullname
                  ORDER BY zt.name";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Get zone template details by ID
     *
     * @param int $id Zone template ID
     * @return array|false Template details or false if not found
     */
    public function getZoneTemplateDetails(int $id): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM zone_templ WHERE id = :id");
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: false;
    }

    /**
     * Get the template name linked to a zone
     *
     * @param int|string $zoneId Zone ID
     * @return string Template name or an empty string
     */
    public function getTemplateNameForZone(int|string $zoneId): string
    {
        $stmt = $this->db->prepare("SELECT zt.name FROM zones z JOIN zone_templ zt ON zt.id = z.zone_templ_id WHERE " . CanonicalZoneSql::canonicalIdColumn('z') . " = :zone_id");
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch();

        return $result ? (string)$result['name'] : '';
    }

    /**
     * Get all records for a zone template
     *
     * @param int $templateId Zone template ID
     * @param int $rowStart Starting row
     * @param int $rowAmount Number of rows
     * @param string $sortBy Column to sort by
     * @return array Template records
     */
    public function getZoneTemplateRecords(
        int $templateId,
        int $rowStart = 0,
        int $rowAmount = Constants::DEFAULT_MAX_ROWS,
        string $sortBy = 'name'
    ): array {
        $allowedSortColumns = ['name', 'type', 'content', 'priority', 'ttl'];
        $sortBy = in_array($sortBy, $allowedSortColumns) ? htmlspecialchars($sortBy) : 'name';

        $query = "SELECT id FROM zone_templ_records WHERE zone_templ_id = :id ORDER BY " . $sortBy;
        if ($rowAmount < Constants::DEFAULT_MAX_ROWS) {
            $query .= " LIMIT " . $rowAmount;
            if ($rowStart > 0) {
                $query .= " OFFSET " . $rowStart;
            }
        }

        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $templateId]);

        $ret = [];
        $retCount = 0;
        while ($r = $stmt->fetch()) {
            // Look each row up in full, so one shape of record array is produced here.
            $ret[$retCount] = $this->getZoneTemplateRecordById((int)$r["id"]);
            $retCount++;
        }

        return ($retCount > 0 ? $ret : []);
    }

    /**
     * Get a single zone template record by ID
     *
     * @param int $id Record ID
     * @param int|null $templateId Restrict the lookup to this template
     * @return array Record details or empty array
     */
    public function getZoneTemplateRecordById(int $id, ?int $templateId = null): array
    {
        $query = "SELECT id, zone_templ_id, name, type, content, ttl, prio FROM zone_templ_records WHERE id = :id";
        $params = [':id' => $id];

        if ($templateId !== null) {
            $query .= " AND zone_templ_id = :zone_templ_id";
            $params[':zone_templ_id'] = $templateId;
        }

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return $result ? array(
            "id" => $result["id"],
            "zone_templ_id" => $result["zone_templ_id"],
            "name" => $result["name"],
            "type" => $result["type"],
            "content" => $result["content"],
            "ttl" => $result["ttl"],
            "prio" => $result["prio"],
        ) : [];
    }

    /**
     * Count records in a zone template
     *
     * @param int $templateId Zone template ID
     * @return int Number of records
     */
    public function countZoneTemplateRecords(int $templateId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(id) FROM zone_templ_records WHERE zone_templ_id = :zone_templ_id");
        $stmt->execute([':zone_templ_id' => $templateId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * ID of the global template flagged is_default, if any
     *
     * @return int|null Template ID or null
     */
    public function findFlaggedDefaultTemplateId(): ?int
    {
        $boolTrue = DbCompat::boolTrue($this->dbType());
        $stmt = $this->db->prepare("SELECT id FROM zone_templ WHERE is_default = $boolTrue AND owner = 0 ORDER BY id LIMIT 1");
        $stmt->execute();
        $dbId = $stmt->fetchColumn();

        return ($dbId !== false && $dbId !== null) ? (int)$dbId : null;
    }

    /**
     * Whether a global template (owner 0) with this ID exists
     *
     * @param int $id Template ID
     * @return bool True if it exists and is global
     */
    public function globalTemplateExists(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM zone_templ WHERE id = :id AND owner = 0");
        $stmt->execute([':id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Find global templates (owner 0) by name
     *
     * @param string $name Template name
     * @return int[] Matching template IDs
     */
    public function findGlobalTemplateIdsByName(string $name): array
    {
        $stmt = $this->db->prepare("SELECT id FROM zone_templ WHERE name = :name AND owner = 0");
        $stmt->execute([':name' => $name]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Flag one global template as the default, clearing every other global template
     *
     * @param int $templateId Template to flag
     */
    public function flagDefaultTemplate(int $templateId): void
    {
        // Atomic CASE-WHEN: prevents concurrent writers from each leaving
        // their chosen row flagged.
        $dbType = $this->dbType();
        $boolTrue = DbCompat::boolTrue($dbType);
        $boolFalse = DbCompat::boolFalse($dbType);
        $stmt = $this->db->prepare(
            "UPDATE zone_templ SET is_default = CASE WHEN id = :id THEN $boolTrue ELSE $boolFalse END WHERE owner = 0"
        );
        $stmt->execute([':id' => $templateId]);
    }

    /**
     * Clear the system-wide default template flag
     */
    public function clearDefaultTemplate(): void
    {
        $dbType = $this->dbType();
        $boolTrue = DbCompat::boolTrue($dbType);
        $boolFalse = DbCompat::boolFalse($dbType);
        $this->db->exec("UPDATE zone_templ SET is_default = $boolFalse WHERE is_default = $boolTrue");
    }

    /**
     * Create a new zone template with a default SOA record
     *
     * @param string $name Template name
     * @param string $description Template description
     * @param int $owner Owner user ID (0 for global)
     * @param int $createdBy Creator user ID
     * @return int New template ID
     * @throws Exception On database error
     */
    public function createZoneTemplate(string $name, string $description, int $owner, int $createdBy): int
    {
        return $this->createZoneTemplateWithRecords($name, $description, $owner, $createdBy, []);
    }

    /**
     * Create a zone template together with its records, in one transaction
     *
     * @param string $name Template name
     * @param string $description Template description
     * @param int $owner Owner user ID (0 for global)
     * @param int $createdBy Creator user ID
     * @param array<int, array{name: string, type: string, content: string, ttl: mixed, prio?: mixed}> $records
     * @return int New template ID
     * @throws Exception On database error
     */
    public function createZoneTemplateWithRecords(
        string $name,
        string $description,
        int $owner,
        int $createdBy,
        array $records
    ): int {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO zone_templ (name, descr, owner, created_by) VALUES (:name, :descr, :owner, :created_by)");
            $stmt->execute([
                ':name' => $name,
                ':descr' => $description,
                ':owner' => $owner,
                ':created_by' => $createdBy
            ]);

            // Pass the Postgres sequence name explicitly; MySQL/SQLite ignore it.
            $templateId = (int)$this->db->lastInsertId('zone_templ_id_seq');

            // Prepared once outside the loop for better performance.
            $recordStmt = $this->db->prepare("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (:zone_templ_id, :name, :type, :content, :ttl, :prio)");

            $hasSOA = false;
            foreach ($records as $record) {
                if ($record['type'] === 'SOA') {
                    $hasSOA = true;
                }

                $recordStmt->execute([
                    ':zone_templ_id' => $templateId,
                    ':name' => $record['name'],
                    ':type' => $record['type'],
                    ':content' => $record['content'],
                    ':ttl' => $record['ttl'],
                    ':prio' => $record['prio'] ?? 0
                ]);
            }

            if (!$hasSOA) {
                $recordStmt->execute([
                    ':zone_templ_id' => $templateId,
                    ':name' => self::DEFAULT_SOA_NAME,
                    ':type' => 'SOA',
                    ':content' => self::DEFAULT_SOA_CONTENT,
                    ':ttl' => (int)$this->config()->get('dns', 'ttl'),
                    ':prio' => 0
                ]);
            }

            $this->db->commit();
            return $templateId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update a zone template
     *
     * @param int $id Template ID
     * @param string $name New name
     * @param string $description New description
     * @param int|null $owner New owner (null to keep current)
     * @return bool True on success
     */
    public function updateZoneTemplate(int $id, string $name, string $description, ?int $owner = null): bool
    {
        $query = 'UPDATE zone_templ SET name = :name, descr = :descr';
        $params = [
            ':name' => $name,
            ':descr' => $description,
            ':id' => $id
        ];

        if ($owner !== null) {
            $query .= ', owner = :owner';
            $params[':owner'] = $owner;
            // Private templates cannot be the default; clear the flag to
            // avoid leaving an orphan that the resolver would then ignore.
            if ($owner !== 0) {
                $query .= ', is_default = ' . DbCompat::boolFalse($this->dbType());
            }
        }

        $query .= ' WHERE id = :id';
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return true;
    }

    /**
     * Delete a zone template and all related records
     *
     * @param int $id Template ID
     * @return bool True on success
     * @throws Exception On database error
     */
    public function deleteZoneTemplate(int $id): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM zone_templ WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $stmt = $this->db->prepare("DELETE FROM zone_templ_records WHERE zone_templ_id = :id");
            $stmt->execute([':id' => $id]);

            $stmt = $this->db->prepare("DELETE FROM records_zone_templ WHERE zone_templ_id = :id");
            $stmt->execute([':id' => $id]);

            $stmt = $this->db->prepare("DELETE FROM records_zone_templ_api WHERE zone_templ_id = :id");
            $stmt->execute([':id' => $id]);

            // Unlink the zones that used it. Leaving the id behind re-links them to
            // whichever template later reuses it (SQLite and MariaDB reuse ids).
            $stmt = $this->db->prepare("UPDATE zones SET zone_templ_id = 0 WHERE zone_templ_id = :id");
            $stmt->execute([':id' => $id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Deletes every template the user owns, with its records and zone links.
     * Runs inside the caller's transaction (used by user deletion).
     */
    public function deleteZoneTemplatesOwnedBy(int $ownerId): void
    {
        $owned = "SELECT id FROM zone_templ WHERE owner = :owner";
        foreach (
            [
                "DELETE FROM zone_templ_records WHERE zone_templ_id IN ($owned)",
                "DELETE FROM records_zone_templ WHERE zone_templ_id IN ($owned)",
                "DELETE FROM records_zone_templ_api WHERE zone_templ_id IN ($owned)",
                // Same unlink as deleteZoneTemplate(): a reused id would re-link the zones.
                "UPDATE zones SET zone_templ_id = 0 WHERE zone_templ_id IN ($owned)",
                "DELETE FROM zone_templ WHERE owner = :owner",
            ] as $query
        ) {
            $stmt = $this->db->prepare($query);
            $stmt->execute([':owner' => $ownerId]);
        }
    }

    /**
     * Add a record to a zone template
     *
     * @param int $templateId Template ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @param int $ttl TTL
     * @param int $prio Priority
     * @return int New record ID
     */
    public function addRecord(int $templateId, string $name, string $type, string $content, int $ttl, int $prio): int
    {
        $content = $this->dnsFormatter()->formatContent($type, $content);

        $stmt = $this->db->prepare("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (:zone_templ_id, :name, :type, :content, :ttl, :prio)");
        $stmt->execute([
            ':zone_templ_id' => $templateId,
            ':name' => $name,
            ':type' => $type,
            ':content' => $content,
            ':ttl' => $ttl,
            ':prio' => $prio
        ]);

        // Pass the Postgres sequence name explicitly; MySQL/SQLite ignore it.
        return (int)$this->db->lastInsertId('zone_templ_records_id_seq');
    }

    /**
     * Update a zone template record
     *
     * @param int $id Record ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @param int $ttl TTL
     * @param int $prio Priority
     * @return bool True on success
     */
    public function updateRecord(int $id, string $name, string $type, string $content, int $ttl, int $prio): bool
    {
        $content = $this->dnsFormatter()->formatContent($type, $content);

        $stmt = $this->db->prepare("UPDATE zone_templ_records SET name = :name, type = :type, content = :content, ttl = :ttl, prio = :prio WHERE id = :id");
        $stmt->execute([
            ':name' => $name,
            ':type' => $type,
            ':content' => $content,
            ':ttl' => $ttl,
            ':prio' => $prio,
            ':id' => $id
        ]);

        return true;
    }

    /**
     * Delete a zone template record
     *
     * @param int $id Record ID
     * @return bool True on success
     */
    public function deleteRecord(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM zone_templ_records WHERE id = :id");
        $stmt->execute([':id' => $id]);

        return true;
    }

    /**
     * Unlink a zone from its template
     *
     * @param int $zoneId Zone ID
     * @return bool True on success
     */
    public function unlinkZoneFromTemplate(int $zoneId): bool
    {
        $stmt = $this->db->prepare("UPDATE zones SET zone_templ_id = 0 WHERE domain_id = ?");
        $stmt->bindValue(1, $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * Check if a zone template exists
     *
     * @param int $id Template ID
     * @return bool True if exists
     */
    public function zoneTemplateExists(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(id) FROM zone_templ WHERE id = :id");
        $stmt->execute([':id' => $id]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Check if a zone template name already exists
     *
     * @param string $name Template name
     * @param int|null $excludeId Exclude this template ID from the check (for updates)
     * @return bool True if name exists
     */
    public function zoneTemplateNameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->db->prepare("SELECT COUNT(id) FROM zone_templ WHERE name = :name AND id != :id");
            $stmt->execute([':name' => $name, ':id' => $excludeId]);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(id) FROM zone_templ WHERE name = :name");
            $stmt->execute([':name' => $name]);
        }

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Find every template ID carrying the given name
     *
     * @param string $name Zone template name
     * @return int[] Matching template IDs
     */
    public function findTemplateIdsByName(string $name): array
    {
        $stmt = $this->db->prepare("SELECT id FROM zone_templ WHERE name = :name");
        $stmt->execute([':name' => $name]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Check if user is the owner of a zone template
     *
     * @param int $templateId Template ID
     * @param int $userId User ID
     * @return bool True if user is owner
     */
    public function isOwner(int $templateId, int $userId): bool
    {
        return $this->getOwner($templateId) == $userId;
    }

    /**
     * Get the owner ID of a zone template
     *
     * @param int $templateId Template ID
     * @return int|null Owner ID or null if not found
     */
    public function getOwner(int $templateId): ?int
    {
        $stmt = $this->db->prepare("SELECT owner FROM zone_templ WHERE id = :id");
        $stmt->execute([':id' => $templateId]);
        $result = $stmt->fetchColumn();

        return ($result !== false && $result !== null) ? (int)$result : null;
    }
}
