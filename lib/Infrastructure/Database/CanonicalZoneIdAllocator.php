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
use Poweradmin\Domain\Database\DbCompat;

/**
 * Picks the canonical id for zone rows this application creates in API backend mode.
 *
 * A new row keeps its own row id as canonical id unless another row, a group assignment or
 * an API key scope already uses that number as a zone id; then it gets the next number
 * above all of them, so it never takes over (or inherits the grants of) another zone's id.
 * The zones rows are read with a row lock, so concurrent creators wait for each other and
 * each sees the ids the other committed. Build one per transaction, after it has begun.
 */
final class CanonicalZoneIdAllocator
{
    /** Advisory lock key for zone id allocation on PostgreSQL (the issue that introduced it). */
    private const PGSQL_LOCK_KEY = 1559;

    /** @var array<int, true> */
    private array $used = [];
    private int $highest = 0;

    public function __construct(PDO $db)
    {
        $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            // Row locks only cover rows a statement sees; this serializes creators even with none
            $db->prepare("SELECT pg_advisory_xact_lock(" . self::PGSQL_LOCK_KEY . ")")->execute();
        }
        $lock = DbCompat::rowLock($driver);
        $zones = $db->prepare("SELECT id, domain_id FROM zones$lock");
        // The first read waits for a concurrent creator to commit; on PostgreSQL it still sees
        // its old snapshot, so a second statement reads the rows that creator committed
        $zones->execute();
        $zones->fetchAll();
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

    public function allocate(int $rowId): int
    {
        $canonicalId = isset($this->used[$rowId]) ? max($this->highest, $rowId) + 1 : $rowId;
        $this->used[$canonicalId] = true;
        $this->highest = max($this->highest, $canonicalId, $rowId);

        return $canonicalId;
    }
}
