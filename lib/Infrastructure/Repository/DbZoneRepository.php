<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Infrastructure\Database\DbCompat;

class DbZoneRepository implements ZoneRepositoryInterface {
    private object $db;
    private string $db_type;

    public function __construct($db, $db_type) {
        $this->db = $db;
        $this->db_type = $db_type;
    }

    public function getDistinctStartingLetters(int $userId, bool $viewOthers): array {
        $query = "SELECT DISTINCT " . DbCompat::substr($this->db_type) . "(domains.name, 1, 1) AS letter FROM domains";

        if (!$viewOthers) {
            $query .= " LEFT JOIN zones ON domains.id = zones.domain_id";
            $query .= " WHERE zones.owner = :userId";
        }

        $query .= " ORDER BY letter";

        $stmt = $this->db->prepare($query);

        if (!$viewOthers) {
            $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        }

        $stmt->execute();

        $letters = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        return array_filter($letters, function ($letter) {
            return ctype_alpha($letter) || is_numeric($letter);
        });
    }
}
