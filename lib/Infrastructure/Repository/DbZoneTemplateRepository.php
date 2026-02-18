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
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class DbZoneTemplateRepository
{
    private object $db;
    private ConfigurationManager $config;
    private DnsFormatter $dnsFormatter;

    public function __construct(object $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->dnsFormatter = new DnsFormatter($config);
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
        $query = "SELECT zt.id, zt.name, zt.descr, zt.owner, zt.created_by,
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

        $query .= " GROUP BY zt.id, zt.name, zt.descr, zt.owner, zt.created_by,
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
        return ZoneTemplate::getZoneTemplDetails($this->db, $id) ?: false;
    }

    /**
     * Get all records for a zone template
     *
     * @param int $templateId Zone template ID
     * @return array Template records
     */
    public function getZoneTemplateRecords(int $templateId): array
    {
        return ZoneTemplate::getZoneTemplRecords($this->db, $templateId);
    }

    /**
     * Get a single zone template record by ID
     *
     * @param int $id Record ID
     * @return array Record details or empty array
     */
    public function getZoneTemplateRecordById(int $id): array
    {
        return ZoneTemplate::getZoneTemplRecordFromId($this->db, $id);
    }

    /**
     * Count records in a zone template
     *
     * @param int $templateId Zone template ID
     * @return int Number of records
     */
    public function countZoneTemplateRecords(int $templateId): int
    {
        return ZoneTemplate::countZoneTemplRecords($this->db, $templateId);
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
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO zone_templ (name, descr, owner, created_by) VALUES (:name, :descr, :owner, :created_by)");
            $stmt->execute([
                ':name' => $name,
                ':descr' => $description,
                ':owner' => $owner,
                ':created_by' => $createdBy
            ]);

            $templateId = (int)$this->db->lastInsertId();

            // Add default SOA record
            $ttl = (int)$this->config->get('dns', 'ttl');
            $stmt = $this->db->prepare("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (:zone_templ_id, :name, :type, :content, :ttl, :prio)");
            $stmt->execute([
                ':zone_templ_id' => $templateId,
                ':name' => '[ZONE]',
                ':type' => 'SOA',
                ':content' => '[NS1] [HOSTMASTER] [SERIAL] 28800 7200 604800 86400',
                ':ttl' => $ttl,
                ':prio' => 0
            ]);

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

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
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
        $content = $this->dnsFormatter->formatContent($type, $content);

        $stmt = $this->db->prepare("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (:zone_templ_id, :name, :type, :content, :ttl, :prio)");
        $stmt->execute([
            ':zone_templ_id' => $templateId,
            ':name' => $name,
            ':type' => $type,
            ':content' => $content,
            ':ttl' => $ttl,
            ':prio' => $prio
        ]);

        return (int)$this->db->lastInsertId();
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
        $content = $this->dnsFormatter->formatContent($type, $content);

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
     * Check if a zone template exists
     *
     * @param int $id Template ID
     * @return bool True if exists
     */
    public function zoneTemplateExists(int $id): bool
    {
        return ZoneTemplate::zoneTemplIdExists($this->db, $id);
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
     * Check if user is the owner of a zone template
     *
     * @param int $templateId Template ID
     * @param int $userId User ID
     * @return bool True if user is owner
     */
    public function isOwner(int $templateId, int $userId): bool
    {
        return ZoneTemplate::getZoneTemplIsOwner($this->db, $templateId, $userId);
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
        return $result !== false ? (int)$result : null;
    }
}
