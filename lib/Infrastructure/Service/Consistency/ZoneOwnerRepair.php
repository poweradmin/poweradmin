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

use PDO;

/**
 * Writes a zone owner into the Poweradmin-native zones table. Ownership lives
 * there under both backends, so the SQL and API strategies share this repair.
 */
final class ZoneOwnerRepair
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Update the zone's ownership row, or insert one when the zone has none. */
    public function assign(int $zoneId, int $userId): bool
    {
        // An unsynced API zone (id 0) has no zones row to own; writing one would
        // leave a dangling row keyed on domain_id 0.
        if ($zoneId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM zones WHERE domain_id = :domain_id");
        $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $exists = $stmt->fetchColumn() > 0;

        $stmt = $this->db->prepare(
            $exists
                ? "UPDATE zones SET owner = :owner WHERE domain_id = :domain_id"
                : "INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (:domain_id, :owner, 0)"
        );
        $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $userId, PDO::PARAM_INT);

        return $stmt->execute();
    }
}
