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

namespace Poweradmin\Infrastructure\Database;

use PDO;
use PDOException;

/**
 * Settles the canonical id of zone rows this application creates in API backend mode.
 *
 * A new row keeps its own row id as canonical id unless another row, a group assignment or
 * an API key scope already uses that number as a zone id. Then the row itself moves to an
 * id above all of them, so it never takes over (or inherits the grants of) another zone's
 * id, and rows created after it get their own ids again. Build one per transaction before
 * inserting: it locks the queue row first and then reads the zones rows, so concurrent
 * creators queue up instead of deadlocking.
 */
final class CanonicalZoneIdAllocator
{
    /** Advisory lock key for zone id allocation on PostgreSQL (the issue that introduced it). */
    private const PGSQL_LOCK_KEY = 1559;

    /** The app_settings row creators queue on. Its value records that zone ids were written in API mode. */
    public const QUEUE_KEY = 'backend.zone_ids';

    /** @var array<int, true> */
    private array $used = [];
    private int $highest = 0;
    private string $driver;

    /**
     * Creates the queue row when it is missing, as its own statement outside any transaction:
     * an insert racing inside the creators' transactions would deadlock them. Returns whether
     * the row can be locked; false when a transaction is already open or app_settings is missing.
     */
    public static function ensureQueueRow(PDO $db): bool
    {
        if ($db->inTransaction()) {
            return false;
        }

        try {
            $stmt = $db->prepare("SELECT 1 FROM app_settings WHERE setting_key = :key");
            $stmt->execute([':key' => self::QUEUE_KEY]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }

            $values = "(setting_key, setting_value, value_type) VALUES (:key, 'api', 'string')";
            $insert = match ((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME)) {
                'mysql' => "INSERT IGNORE INTO app_settings $values",
                'pgsql' => "INSERT INTO app_settings $values ON CONFLICT (setting_key) DO NOTHING",
                default => "INSERT OR IGNORE INTO app_settings $values",
            };
            $db->prepare($insert)->execute([':key' => self::QUEUE_KEY]);

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @param bool $queue Whether ensureQueueRow() returned true; the queue row is then locked first
     */
    public function __construct(private readonly PDO $db, bool $queue = false)
    {
        $this->driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($queue) {
            // An exclusive row lock on MySQL and a write-first transaction on SQLite, where
            // busy_timeout then applies. Locking zones first deadlocks on InnoDB gap locks.
            $lock = $db->prepare("UPDATE app_settings SET setting_value = 'api' WHERE setting_key = :key");
            $lock->execute([':key' => self::QUEUE_KEY]);
        }
        if ($this->driver === 'pgsql') {
            // Row locks only cover rows a statement sees; this serializes creators even with none
            $db->prepare("SELECT pg_advisory_xact_lock(" . self::PGSQL_LOCK_KEY . ")")->execute();
        }
        $zones = $db->prepare("SELECT id, domain_id FROM zones" . DbCompat::rowLock($this->driver));
        $zones->execute();
        foreach ($zones->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $domainId = (int)($row['domain_id'] ?? 0);
            // A row without domain_id is keyed by its own id, which no other row can reuse
            $this->highest = max($this->highest, $domainId, (int)$row['id']);
            if ($domainId > 0) {
                $this->used[$domainId] = true;
            }
        }

        foreach (["SELECT domain_id FROM zones_groups", "SELECT zone_id FROM api_key_zones"] as $sql) {
            $stmt = $db->prepare($sql);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $zoneId) {
                $this->used[(int)$zoneId] = true;
                $this->highest = max($this->highest, (int)$zoneId);
            }
        }
    }

    /**
     * Give the row just inserted under $rowId its canonical id and return it; the row's id
     * changes to that value too when its own id was taken.
     */
    public function settle(int $rowId): int
    {
        $zoneId = $rowId;
        if (isset($this->used[$rowId])) {
            $zoneId = max($this->highest, $rowId) + 1;
            $this->moveRow($rowId, $zoneId);
        } else {
            $stmt = $this->db->prepare("UPDATE zones SET domain_id = :zone_id WHERE id = :id");
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->bindValue(':id', $rowId, PDO::PARAM_INT);
            $stmt->execute();
        }

        $this->used[$zoneId] = true;
        $this->highest = max($this->highest, $zoneId);
        SharedZoneIds::forget($this->db);

        return $zoneId;
    }

    private function moveRow(int $rowId, int $zoneId): void
    {
        $read = $this->db->prepare("SELECT owner, comment, zone_templ_id, zone_name, zone_type, zone_master FROM zones WHERE id = :id");
        $read->bindValue(':id', $rowId, PDO::PARAM_INT);
        $read->execute();
        $row = $read->fetch(PDO::FETCH_ASSOC);

        // Delete first: zone_name is unique
        $delete = $this->db->prepare("DELETE FROM zones WHERE id = :id");
        $delete->bindValue(':id', $rowId, PDO::PARAM_INT);
        $delete->execute();

        $insert = $this->db->prepare(
            "INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type, zone_master)
             VALUES (:id, :domain_id, :owner, :comment, :zone_templ_id, :zone_name, :zone_type, :zone_master)"
        );
        $insert->bindValue(':id', $zoneId, PDO::PARAM_INT);
        $insert->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $insert->bindValue(':owner', $row['owner'] ?? null, isset($row['owner']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $insert->bindValue(':comment', $row['comment'] ?? null, isset($row['comment']) ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insert->bindValue(':zone_templ_id', (int)($row['zone_templ_id'] ?? 0), PDO::PARAM_INT);
        $insert->bindValue(':zone_name', $row['zone_name'] ?? null, isset($row['zone_name']) ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insert->bindValue(':zone_type', $row['zone_type'] ?? null, isset($row['zone_type']) ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insert->bindValue(':zone_master', $row['zone_master'] ?? null, isset($row['zone_master']) ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insert->execute();

        // MySQL and SQLite move their counters past an explicit id; PostgreSQL needs telling
        if ($this->driver === 'pgsql') {
            $this->db->prepare("SELECT setval('zones_id_seq', GREATEST((SELECT MAX(id) FROM zones), $zoneId))")->execute();
        }
    }
}
