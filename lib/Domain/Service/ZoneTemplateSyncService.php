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

use PDO;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Service for managing zone template synchronization tracking
 */
class ZoneTemplateSyncService
{
    private PDO $db;
    private ConfigurationInterface $config;

    public function __construct(PDO $db, ConfigurationInterface $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Mark all zones using a template as needing sync
     */
    public function markTemplateAsModified(int $templateId): void
    {
        $query = "UPDATE zone_template_sync 
                  SET needs_sync = 1, 
                      template_last_modified = NOW() 
                  WHERE zone_templ_id = :template_id";

        $stmt = $this->db->prepare($query);
        $stmt->execute(['template_id' => $templateId]);
    }

    /**
     * Mark a specific zone as synced with its template
     */
    public function markZoneAsSynced(int $zoneId, int $templateId): void
    {
        $query = "UPDATE zone_template_sync 
                  SET needs_sync = 0, 
                      last_synced = NOW() 
                  WHERE zone_id = :zone_id 
                    AND zone_templ_id = :template_id";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            'zone_id' => $zoneId,
            'template_id' => $templateId
        ]);
    }

    /**
     * Mark multiple zones as synced
     */
    public function markZonesAsSynced(array $zoneIds, int $templateId): void
    {
        if (empty($zoneIds)) {
            return;
        }

        $placeholders = array_fill(0, count($zoneIds), '?');
        $query = "UPDATE zone_template_sync 
                  SET needs_sync = 0, 
                      last_synced = NOW() 
                  WHERE zone_id IN (" . implode(',', $placeholders) . ") 
                    AND zone_templ_id = ?";

        $params = array_merge($zoneIds, [$templateId]);
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
    }

    /**
     * Create sync tracking record when zone is linked to template
     */
    public function createSyncRecord(int $zoneId, int $templateId): void
    {
        $query = "INSERT INTO zone_template_sync (zone_id, zone_templ_id, needs_sync) 
                  VALUES (:zone_id, :template_id, 1) 
                  ON DUPLICATE KEY UPDATE needs_sync = 1";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            'zone_id' => $zoneId,
            'template_id' => $templateId
        ]);
    }

    /**
     * Remove sync tracking when zone is unlinked from template
     */
    public function removeSyncRecord(int $zoneId, int $templateId): void
    {
        $query = "DELETE FROM zone_template_sync 
                  WHERE zone_id = :zone_id 
                    AND zone_templ_id = :template_id";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            'zone_id' => $zoneId,
            'template_id' => $templateId
        ]);
    }

    /**
     * Get count of zones needing sync for a template
     */
    public function getUnsyncedZoneCount(int $templateId): int
    {
        $query = "SELECT COUNT(*) as count 
                  FROM zone_template_sync 
                  WHERE zone_templ_id = :template_id 
                    AND needs_sync = 1";

        $stmt = $this->db->prepare($query);
        $stmt->execute(['template_id' => $templateId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get sync status for all templates
     */
    public function getTemplateSyncStatus(?int $userId = null): array
    {
        $query = "SELECT 
                    zt.id,
                    zt.name,
                    COUNT(zts.zone_id) as total_zones,
                    SUM(CASE WHEN zts.needs_sync = 1 THEN 1 ELSE 0 END) as unsynced_zones
                  FROM zone_templ zt
                  LEFT JOIN zone_template_sync zts ON zt.id = zts.zone_templ_id";

        if ($userId !== null) {
            $query .= " WHERE zt.owner = :user_id OR zt.owner = 0";
        }

        $query .= " GROUP BY zt.id, zt.name";

        $stmt = $this->db->prepare($query);
        if ($userId !== null) {
            $stmt->execute(['user_id' => $userId]);
        } else {
            $stmt->execute();
        }

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['id']] = [
                'name' => $row['name'],
                'total_zones' => (int) $row['total_zones'],
                'unsynced_zones' => (int) $row['unsynced_zones'],
                'is_synced' => $row['unsynced_zones'] == 0
            ];
        }

        return $results;
    }

    /**
     * Get list of zones needing sync for a template
     */
    public function getUnsyncedZones(int $templateId): array
    {
        $query = "SELECT 
                    d.id,
                    d.name,
                    zts.template_last_modified,
                    zts.last_synced
                  FROM zone_template_sync zts
                  JOIN zones z ON zts.zone_id = z.id
                  JOIN domains d ON z.domain_id = d.id
                  WHERE zts.zone_templ_id = :template_id 
                    AND zts.needs_sync = 1
                  ORDER BY d.name";

        $stmt = $this->db->prepare($query);
        $stmt->execute(['template_id' => $templateId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if a specific zone needs sync
     */
    public function zoneNeedsSync(int $zoneId, int $templateId): bool
    {
        $query = "SELECT needs_sync 
                  FROM zone_template_sync 
                  WHERE zone_id = :zone_id 
                    AND zone_templ_id = :template_id";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            'zone_id' => $zoneId,
            'template_id' => $templateId
        ]);

        $result = $stmt->fetchColumn();
        // If no sync record exists, assume it needs sync (should be created)
        return $result === false ? true : (bool) $result;
    }

    /**
     * Initialize sync records for existing zone-template relationships
     */
    public function initializeSyncRecords(): void
    {
        // Insert sync records for all existing zone-template relationships
        $query = "INSERT INTO zone_template_sync (zone_id, zone_templ_id, needs_sync, last_synced)
                  SELECT z.id, z.zone_templ_id, 0, NOW()
                  FROM zones z
                  WHERE z.zone_templ_id > 0
                  ON DUPLICATE KEY UPDATE zone_id = zone_id";

        $this->db->exec($query);
    }
}
