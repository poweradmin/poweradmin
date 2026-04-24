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

namespace Poweradmin\Application\Service;

use PDO;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Synchronizes zone metadata between the PowerDNS API and the local zones table.
 *
 * In API mode, zones created directly in PowerDNS (outside Poweradmin) won't
 * appear in the local zones table. This service reconciles the two by:
 * - Adding missing zones (present in API but not locally)
 * - Removing orphaned zones (present locally but deleted from API)
 * - Updating cached metadata (zone_type, zone_master) when changed
 */
class ZoneSyncService
{
    private PDO $db;
    private DnsBackendProvider $backendProvider;
    private LoggerInterface $logger;

    /** @var int Minimum seconds between syncs */
    private int $syncInterval;

    /** @var string Session key for tracking last sync time */
    private const LAST_SYNC_KEY = 'zone_sync_last';

    public function __construct(PDO $db, DnsBackendProvider $backendProvider, int $syncInterval = 300, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->backendProvider = $backendProvider;
        $this->syncInterval = $syncInterval;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Run sync only if enough time has passed since the last sync.
     *
     * @return array|null Sync result or null if skipped
     */
    public function syncIfStale(): ?array
    {
        $lastSync = $_SESSION[self::LAST_SYNC_KEY] ?? 0;
        $age = time() - $lastSync;
        if ($age < $this->syncInterval) {
            $this->logger->debug('Zone sync skipped (last ran {age}s ago, interval {interval}s)', [
                'age' => $age,
                'interval' => $this->syncInterval,
            ]);
            return null;
        }

        try {
            $result = $this->sync();
        } catch (\Throwable $e) {
            $this->logger->warning('Zone sync failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            return null;
        }

        $_SESSION[self::LAST_SYNC_KEY] = time();
        return $result;
    }

    /**
     * Synchronize zones from PowerDNS API with local zones table.
     *
     * @return array{added: int, removed: int, updated: int}
     */
    public function sync(): array
    {
        $this->logger->debug('Zone sync starting');
        $apiZones = $this->backendProvider->getZones();
        $localZones = $this->getLocalZones();
        $this->logger->debug('Zone sync fetched {api} API zones, {local} local zones', [
            'api' => count($apiZones),
            'local' => count($localZones),
        ]);

        // When the API is unreachable, getZones() returns [] after catching the
        // exception internally. Without this guard we would delete every local
        // zone, mistaking an outage for "all zones removed." The trade-off is
        // that a genuine "delete every zone in PowerDNS" won't auto-sync; an
        // admin must remove the local entries manually in that rare scenario.
        if (empty($apiZones) && !empty($localZones)) {
            $this->logger->warning('Zone sync: API returned empty while local zones exist, skipping removal');
            return ['added' => 0, 'removed' => 0, 'updated' => 0];
        }

        $added = $this->addMissingZones($apiZones, $localZones);
        $removed = $this->removeOrphanedZones($apiZones, $localZones);
        $updated = $this->updateZoneMetadata($apiZones, $localZones);

        $result = [
            'added' => $added,
            'removed' => $removed,
            'updated' => $updated,
        ];
        if ($added || $removed || $updated) {
            $this->logger->info('Zone sync complete: added={added}, removed={removed}, updated={updated}', $result);
        } else {
            $this->logger->debug('Zone sync complete: no changes', $result);
        }
        return $result;
    }

    /**
     * Get all zones from local table indexed by zone_name.
     *
     * @return array<string, array{id: int, zone_type: string|null, zone_master: string|null}>
     */
    private function getLocalZones(): array
    {
        $stmt = $this->db->query("SELECT id, zone_name, zone_type, zone_master FROM zones WHERE zone_name IS NOT NULL");
        if (!$stmt) {
            return [];
        }
        $zones = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $zones[$row['zone_name']] = [
                'id' => (int)$row['id'],
                'zone_type' => $row['zone_type'],
                'zone_master' => $row['zone_master'],
            ];
        }
        return $zones;
    }

    /**
     * Add zones that exist in the API but not in the local table.
     *
     * New zones get no owner - they're available for assignment by admins.
     */
    private function addMissingZones(array $apiZones, array $localZones): int
    {
        $apiByName = [];
        foreach ($apiZones as $zone) {
            $apiByName[$zone['name']] = $zone;
        }

        $missing = array_diff_key($apiByName, $localZones);
        if (empty($missing)) {
            return 0;
        }

        $insertStmt = $this->db->prepare(
            "INSERT INTO zones (domain_id, owner, zone_templ_id, comment, zone_name, zone_type, zone_master)
             VALUES (NULL, 0, 0, '', :zone_name, :zone_type, :zone_master)"
        );
        $updateStmt = $this->db->prepare("UPDATE zones SET domain_id = :did WHERE id = :id");

        // Wrap in a single transaction so the initial sync on a large PowerDNS
        // (thousands of zones) completes in seconds instead of minutes. Without
        // this each INSERT+UPDATE pair is auto-committed individually.
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        $count = 0;
        try {
            foreach ($missing as $name => $zone) {
                $insertStmt->bindValue(':zone_name', $name, PDO::PARAM_STR);
                $insertStmt->bindValue(':zone_type', $zone['type'] ?? null, PDO::PARAM_STR);
                $insertStmt->bindValue(':zone_master', $zone['master'] ?? null, PDO::PARAM_STR);
                if ($insertStmt->execute()) {
                    // Set domain_id = id (self-referencing for API mode compatibility)
                    $id = $this->db->lastInsertId();
                    $updateStmt->execute([':did' => $id, ':id' => $id]);
                    $count++;
                }
            }
            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $count;
    }

    /**
     * Remove local zones that no longer exist in the API.
     */
    private function removeOrphanedZones(array $apiZones, array $localZones): int
    {
        $apiNames = [];
        foreach ($apiZones as $zone) {
            $apiNames[$zone['name']] = true;
        }

        $orphaned = array_diff_key($localZones, $apiNames);
        if (empty($orphaned)) {
            return 0;
        }

        $count = 0;
        foreach ($orphaned as $name => $local) {
            $zoneId = $local['id'];

            // Delete group associations
            $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :id");
            $stmt->execute([':id' => $zoneId]);

            // Delete zone record
            $stmt = $this->db->prepare("DELETE FROM zones WHERE id = :id");
            $stmt->execute([':id' => $zoneId]);
            $count++;
        }

        return $count;
    }

    /**
     * Update cached zone_type and zone_master for zones that exist in both.
     */
    private function updateZoneMetadata(array $apiZones, array $localZones): int
    {
        $apiByName = [];
        foreach ($apiZones as $zone) {
            $apiByName[$zone['name']] = $zone;
        }

        $count = 0;
        foreach ($localZones as $name => $local) {
            if (!isset($apiByName[$name])) {
                continue;
            }

            $apiZone = $apiByName[$name];
            $apiType = $apiZone['type'] ?? null;
            $apiMaster = $apiZone['master'] ?? null;

            if ($local['zone_type'] !== $apiType || $local['zone_master'] !== $apiMaster) {
                $stmt = $this->db->prepare(
                    "UPDATE zones SET zone_type = :type, zone_master = :master WHERE id = :id"
                );
                $stmt->bindValue(':type', $apiType, PDO::PARAM_STR);
                $stmt->bindValue(':master', $apiMaster, PDO::PARAM_STR);
                $stmt->bindValue(':id', $local['id'], PDO::PARAM_INT);
                $stmt->execute();
                $count++;
            }
        }

        return $count;
    }
}
