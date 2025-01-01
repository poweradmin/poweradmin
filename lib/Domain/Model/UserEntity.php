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

namespace Poweradmin\Domain\Model;

class UserEntity
{
    public static function exists($db, $name): bool
    {
        if ($name == "") {
            return false;
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE username=:user");
        $stmt->execute(['user' => $name]);

        return (bool)$stmt->fetch();
    }

    public static function get_username_by_id($db, $user_id): string
    {
        if ($user_id == "") {
            return "";
        }

        $stmt = $db->prepare("SELECT username FROM users WHERE id=:user_id");
        $stmt->execute(['user_id' => $user_id]);

        $user = $stmt->fetch();

        return $user['username'] ?: "";
    }
}