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

namespace Poweradmin\Infrastructure\Repository;

use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\UserRepository;

class DbUserRepository implements UserRepository {
    private object $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function canViewOthersContent(User $user): bool {
        $query = "SELECT DISTINCT u.id
                  FROM users u
                  JOIN perm_templ pt ON u.perm_templ = pt.id
                  JOIN perm_templ_items pti ON pti.templ_id = pt.id
                  JOIN (SELECT id FROM perm_items WHERE name IN ('zone_content_view_others', 'user_is_ueberuser')) pit ON pti.perm_id = pit.id
                  WHERE u.id = :userId";

        $stmt = $this->db->prepare($query);
        $stmt->execute(['userId' => $user->getId()]);

        return (bool)$stmt->fetchColumn();
    }
}
