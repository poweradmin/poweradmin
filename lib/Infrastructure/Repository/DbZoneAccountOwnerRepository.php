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
use Poweradmin\Domain\Database\CanonicalZoneSql;
use Poweradmin\Domain\Repository\ZoneAccountOwnerLookupInterface;

/**
 * Reads the oldest direct owner of a zone from the native zones table.
 */
final class DbZoneAccountOwnerRepository implements ZoneAccountOwnerLookupInterface
{
    /**
     * @param bool $rowIdFallback Whether zones.id may stand in for a missing domain_id (API backend)
     */
    public function __construct(private readonly PDO $db, private readonly bool $rowIdFallback)
    {
    }

    public function oldestOwnerUsername(int $domainId): ?string
    {
        // A miss here does not merely skip the sync, it pushes an empty account and wipes
        // whatever PowerDNS held, so the lookup has to resolve the canonical id.
        $canonicalId = CanonicalZoneSql::canonicalIdColumn('z', $this->rowIdFallback);
        $stmt = $this->db->prepare("
            SELECT u.username
            FROM users u
            INNER JOIN zones z ON z.owner = u.id
            WHERE $canonicalId = ?
            ORDER BY z.id
            LIMIT 1
        ");
        $stmt->bindValue(1, $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $account = $stmt->fetchColumn();

        return $account === false ? null : (string)$account;
    }
}
