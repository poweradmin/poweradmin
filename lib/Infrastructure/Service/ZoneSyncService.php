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

use Poweradmin\Infrastructure\Database\SharedZoneIds;
use Poweradmin\Infrastructure\Database\CanonicalZoneIdAllocator;
use Poweradmin\Infrastructure\Database\DeadlockRetry;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Domain\Port\TransactionInterface;
use PDO;
use Poweradmin\Domain\Port\SessionInterface;
use Poweradmin\Infrastructure\Session\ApiStatusService;
use Poweradmin\Domain\Port\ZoneReadBackendInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Poweradmin\Infrastructure\Repository\AccountOwnerLookup;

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
    private TransactionInterface $transaction;
    private ZoneReadBackendInterface $backendProvider;
    private LoggerInterface $logger;
    private SessionInterface $session;
    private ?AccountOwnerLookup $accountOwners;

    /** @var int Minimum seconds between syncs */
    private int $syncInterval;

    /** @var string Session key for tracking last sync time */
    private const LAST_SYNC_KEY = 'zone_sync_last';

    /**
     * @param AccountOwnerLookup|null $accountOwners Gives a new zone to the user its PowerDNS account names (dns.adopt_zone_owner_from_account)
     */
    public function __construct(
        PDO $db,
        ZoneReadBackendInterface $backendProvider,
        SessionInterface $session,
        int $syncInterval = 300,
        ?LoggerInterface $logger = null,
        ?TransactionInterface $transaction = null,
        ?AccountOwnerLookup $accountOwners = null
    ) {
        $this->accountOwners = $accountOwners;
        $this->transaction = $transaction ?? new PdoTransaction($db);
        $this->session = $session;
        $this->db = $db;
        $this->backendProvider = $backendProvider;
        $this->syncInterval = $syncInterval;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Run sync only if enough time has passed since the last sync.
     *
     * @param bool $withDnssec Match the zone-list variant the caller reads next,
     *                         so both share one response instead of fetching
     *                         the list under two URLs
     * @return array|null Sync result or null if skipped
     */
    public function syncIfStale(bool $withDnssec = false): ?array
    {
        $lastSync = $this->session->get(self::LAST_SYNC_KEY, 0);
        $age = time() - $lastSync;
        if ($age < $this->syncInterval) {
            $this->logger->debug('Zone sync skipped (last ran {age}s ago, interval {interval}s)', [
                'age' => $age,
                'interval' => $this->syncInterval,
            ]);
            return null;
        }

        try {
            return $this->reconcile($withDnssec, false);
        } catch (\Throwable $e) {
            $this->logger->warning('Zone sync failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            return null;
        }
    }

    /**
     * Synchronize zones from PowerDNS API with local zones table.
     *
     * On success, records the completion time in the session so that an
     * immediately following syncIfStale() call (e.g. from the redirected
     * request after a manual sync) will skip rather than re-run the full
     * reconciliation.
     *
     * @return array{added: int, removed: int, updated: int}
     */
    public function sync(bool $withDnssec = false): array
    {
        return $this->reconcile($withDnssec, true);
    }

    /**
     * @param bool $mayRemoveMost Whether the run may remove most local zones; only an
     *                            admin's manual sync may, the automatic one changes nothing
     * @return array{added: int, removed: int, updated: int}
     */
    private function reconcile(bool $withDnssec, bool $mayRemoveMost): array
    {
        $this->logger->debug('Zone sync starting');

        $apiStatusService = new ApiStatusService($this->session);
        $syncStartedAt = time();

        // Only name/kind/master are mirrored, so the DNSSEC lookup is skipped
        // unless the caller is about to read the list with it anyway
        $apiZones = $this->backendProvider->getZones($withDnssec);

        // getZones() swallows API errors; if one was recorded during this call,
        // fail the sync so manual callers see it and the throttle stays open.
        $error = $apiStatusService->getLastError();
        if (
            $error !== null
            && (($error['context']['endpoint'] ?? null) === 'zones')
            && isset($error['timestamp']) && $error['timestamp'] >= $syncStartedAt
        ) {
            throw new \RuntimeException(
                sprintf('PowerDNS API unreachable during zone sync: %s', $error['message'])
            );
        }

        $localZones = $this->getLocalZones();
        $this->logger->debug('Zone sync fetched {api} API zones, {local} local zones', [
            'api' => count($apiZones),
            'local' => count($localZones),
        ]);

        // An empty or different PowerDNS behind pdns_api.url would drop every owner and grant,
        // and importing its zones first would hide the gap from the next run, so change nothing
        $missing = count(array_diff_key($localZones, array_column($apiZones, 'name', 'name')));
        if (!$mayRemoveMost && $missing * 2 > count($localZones)) {
            $this->logger->warning(
                'Zone sync: PowerDNS no longer lists {count} of {local} zones, so the automatic sync changes nothing. '
                . 'Check pdns_api.url, then use the manual sync on the zone list',
                ['count' => $missing, 'local' => count($localZones)]
            );
            $this->session->set(self::LAST_SYNC_KEY, time());
            return ['added' => 0, 'removed' => 0, 'updated' => 0];
        }

        // Reaching here means HTTP 200 with an empty list - reconcile so local zones
        // can shrink to zero when an operator deletes every zone in PowerDNS directly.
        if (empty($apiZones) && !empty($localZones)) {
            $this->logger->info('Zone sync: PowerDNS reports zero zones, removing {count} stale local row(s)', [
                'count' => count($localZones),
            ]);
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
        $this->session->set(self::LAST_SYNC_KEY, time());
        return $result;
    }

    /**
     * Get all zones from local table indexed by zone_name.
     *
     * @return array<string, array{id: int, zone_type: string|null, zone_master: string|null}>
     */
    private function getLocalZones(): array
    {
        $stmt = $this->db->query("SELECT id, domain_id, zone_name, zone_type, zone_master FROM zones WHERE zone_name IS NOT NULL");
        if (!$stmt) {
            return [];
        }
        $zones = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $zones[$row['zone_name']] = [
                'id' => (int)$row['id'],
                'canonical_id' => (int)(($row['domain_id'] ?? 0) ?: $row['id']),
                'zone_type' => $row['zone_type'],
                'zone_master' => $row['zone_master'],
            ];
        }
        return $zones;
    }

    /**
     * Add zones that exist in the API but not in the local table.
     *
     * New zones get no owner - they're available for assignment by admins - unless
     * account adoption is on and the zone's PowerDNS account is a Poweradmin username
     * whose zone limit leaves room.
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
             VALUES (NULL, :owner, 0, '', :zone_name, :zone_type, :zone_master)"
        );

        // Wrap in a single transaction so the initial sync on a large PowerDNS
        // (thousands of zones) completes in seconds instead of minutes. Without
        // this each insert and its canonical id would be auto-committed individually.
        $ownsTransaction = !$this->transaction->inTransaction();
        $attempt = function () use ($missing, $insertStmt, $ownsTransaction): int {
            // Before the transaction opens, and again on a retry, which resets the lookup's counters
            $adopters = $this->accountOwners?->limitedAdopters(array_map(static fn(array $zone): string => (string)($zone['account'] ?? ''), array_values($missing))) ?? [];
            if ($ownsTransaction) {
                $this->transaction->begin();
            }

            $count = 0;
            try {
                // User rows before the zones rows, the order every grant locks them in
                $this->accountOwners?->lockAdopters($adopters);
                // Locks the backend marker row before any insert, so a concurrent zone create waits
                $allocator = new CanonicalZoneIdAllocator($this->db);
                foreach ($missing as $name => $zone) {
                    $insertStmt->bindValue(':owner', $this->accountOwners?->adopterFor((string)($zone['account'] ?? '')) ?? 0, PDO::PARAM_INT);
                    $insertStmt->bindValue(':zone_name', $name, PDO::PARAM_STR);
                    $insertStmt->bindValue(':zone_type', $zone['type'] ?? null, PDO::PARAM_STR);
                    $insertStmt->bindValue(':zone_master', $zone['master'] ?? null, PDO::PARAM_STR);
                    if ($insertStmt->execute()) {
                        // The canonical id: the row id, unless another zone or grant already uses that number
                        $allocator->settle((int)$this->db->lastInsertId('zones_id_seq'));
                        $count++;
                    }
                }
                if ($ownsTransaction) {
                    $this->transaction->commit();
                }
            } catch (\Throwable $e) {
                if ($ownsTransaction && $this->transaction->inTransaction()) {
                    $this->transaction->rollBack();
                }
                throw $e;
            }

            return $count;
        };

        // A caller's transaction cannot be replayed from here, so only our own is retried
        $count = $ownsTransaction ? DeadlockRetry::run($attempt) : $attempt();

        $heldBack = $this->accountOwners?->heldBack() ?? 0;
        if ($heldBack > 0) {
            $this->logger->warning('Zone sync left {count} zone(s) without an owner: their account names a user at the zone limit', ['count' => $heldBack]);
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
        SharedZoneIds::forget($this->db);
        foreach ($orphaned as $name => $local) {
            $zoneId = $local['id'];
            $canonicalId = $local['canonical_id'] ?? $zoneId;

            // Grants and extra owners are keyed by the canonical id; on an id another zone
            // shares they cannot be attributed, so they stay (see SharedZoneIds)
            if (!SharedZoneIds::isShared($this->db, $canonicalId)) {
                $stmt = $this->db->prepare("DELETE FROM zones_groups WHERE domain_id = :id");
                $stmt->execute([':id' => $canonicalId]);
                $stmt = $this->db->prepare("DELETE FROM zones WHERE zone_name IS NULL AND domain_id = :id");
                $stmt->execute([':id' => $canonicalId]);
            }

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
