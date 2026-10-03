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

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites API key zone scopes from zones row ids to canonical zone ids, once.
 *
 * Before 4.6.0 the API key form stored the row id of each zone in API backend mode, while
 * every request compared the canonical id, so on installs migrated from SQL mode a scope
 * named the wrong zone. The marker row in app_settings doubles as the lock: only the
 * request that inserts it runs the rewrite, so the overlapping id spaces are never mapped
 * twice. SQL mode stores domains.id, already canonical, and only sets the marker.
 */
final class ApiKeyZoneScopeMigration
{
    public const MARKER = 'migration.api_key_zones_canonical_ids';

    /** Scope value for a stored id whose meaning is unknown: it matches no zone. */
    private const UNREACHABLE_ZONE_ID = 0;

    public function __construct(
        private readonly PDO $db,
        private readonly bool $isApiBackend,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run the rewrite unless it already ran. Returns whether stored scopes are canonical:
     * always in SQL mode, and in API mode once the rewrite has committed.
     */
    public function runOnce(): bool
    {
        try {
            if ($this->isDone()) {
                return true;
            }
            if ($this->db->inTransaction()) {
                return !$this->isApiBackend;
            }

            $this->db->beginTransaction();
            if (!$this->claimMarker()) {
                $this->db->rollBack();
                return $this->isDone() || !$this->isApiBackend;
            }
            if ($this->isApiBackend) {
                $this->rewriteScopes();
            }
            $this->db->commit();

            return true;
        } catch (Throwable $e) {
            // Never fail the request: the marker stays unset, so the next request retries
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('API key zone scope migration failed: {error}', ['error' => $e->getMessage()]);

            return !$this->isApiBackend;
        }
    }

    /**
     * Read fresh each time: a concurrent request may have committed the marker meanwhile.
     *
     * @phpstan-impure
     */
    private function isDone(): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM app_settings WHERE setting_key = :key");
        $stmt->bindValue(':key', self::MARKER);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /** Whether this request inserted the marker; a concurrent one that got there first wins. */
    private function claimMarker(): bool
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO app_settings (setting_key, setting_value, value_type) VALUES (:key, :value, 'string')");
            $stmt->bindValue(':key', self::MARKER);
            $stmt->bindValue(':value', $this->isApiBackend ? 'rewritten' : 'not needed');
            $stmt->execute();

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function rewriteScopes(): void
    {
        $canonicalByRowId = [];
        $rowIdsByCanonical = [];
        foreach ($this->rows("SELECT id, domain_id FROM zones WHERE zone_name IS NOT NULL") as $zone) {
            $rowId = (int)$zone['id'];
            $canonicalId = (int)($zone['domain_id'] ?: $zone['id']);
            $canonicalByRowId[$rowId] = $canonicalId;
            $rowIdsByCanonical[$canonicalId][] = $rowId;
        }

        $storedByKey = [];
        foreach ($this->rows("SELECT api_key_id, zone_id FROM api_key_zones ORDER BY id") as $scope) {
            $storedByKey[(int)$scope['api_key_id']][] = (int)$scope['zone_id'];
        }

        $delete = $this->db->prepare("DELETE FROM api_key_zones WHERE api_key_id = :key_id");
        $insert = $this->db->prepare("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (:key_id, :zone_id)");
        foreach ($storedByKey as $keyId => $stored) {
            // Every target first, then one rewrite per key, so the order rows were saved in never matters
            $targets = [];
            foreach ($stored as $zoneId) {
                $target = $this->targetFor($zoneId, $canonicalByRowId, $rowIdsByCanonical);
                if ($target === self::UNREACHABLE_ZONE_ID) {
                    $this->logger->warning('API key {key}: zone scope {zone} names no zone row and now matches none; re-select the zone on the key', ['key' => $keyId, 'zone' => $zoneId]);
                }
                $targets[$target] = true;
            }
            $targets = array_keys($targets);
            if ($targets == array_values(array_unique($stored))) {
                continue;
            }

            $delete->bindValue(':key_id', $keyId, PDO::PARAM_INT);
            $delete->execute();
            foreach ($targets as $target) {
                $insert->bindValue(':key_id', $keyId, PDO::PARAM_INT);
                $insert->bindValue(':zone_id', $target, PDO::PARAM_INT);
                $insert->execute();
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The canonical id a stored scope value meant. The API-mode form only ever stored row
     * ids, so a row id maps to its zone's canonical id even when another zone's canonical id
     * is the same number. A value that is no zone's row id but some zone's canonical id could
     * be a scope saved in SQL mode or a deleted zone's row id, so it matches nothing
     * afterwards; one that names no zone at all is kept, as it can widen nothing.
     *
     * @param array<int, int> $canonicalByRowId
     * @param array<int, int[]> $rowIdsByCanonical
     */
    private function targetFor(int $stored, array $canonicalByRowId, array $rowIdsByCanonical): int
    {
        if (isset($canonicalByRowId[$stored])) {
            return $canonicalByRowId[$stored];
        }

        return isset($rowIdsByCanonical[$stored]) ? self::UNREACHABLE_ZONE_ID : $stored;
    }
}
