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
use PDOException;
use Poweradmin\Domain\Repository\SamlAssertionStoreInterface;

/**
 * SQL store of used SAML assertion IDs (saml_assertion_ids). Times are UTC.
 */
final class DbSamlAssertionRepository implements SamlAssertionStoreInterface
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function consume(string $assertionId, int $expiresAt): bool
    {
        $stmt = $this->db->prepare("DELETE FROM saml_assertion_ids WHERE not_on_or_after < :now");
        $stmt->execute([':now' => gmdate('Y-m-d H:i:s')]);

        try {
            $stmt = $this->db->prepare("INSERT INTO saml_assertion_ids (assertion_id, not_on_or_after) VALUES (:id, :expires)");
            $stmt->execute([':id' => $assertionId, ':expires' => gmdate('Y-m-d H:i:s', $expiresAt)]);
        } catch (PDOException $e) {
            // 23000 MySQL/SQLite, 23505 PostgreSQL: the unique key on assertion_id
            if (in_array((string)$e->getCode(), ['23000', '23505'], true)) {
                return false;
            }
            throw $e;
        }

        return true;
    }
}
