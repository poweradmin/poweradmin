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

use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Class Permission
 *
 * This class handles permission checks for various actions.
 */
class Permission
{
    /**
     * Get view permission.
     *
     * This method determines the user's permission to view content.
     *
     * @return string Returns "all", "own", or "none" depending on the user's view permission.
     */
    public static function getViewPermission($db): string
    {
        if (UserManager::verify_permission($db, 'zone_content_view_others')) {
            return "all";
        } elseif (UserManager::verify_permission($db, 'zone_content_view_own')) {
            return "own";
        } else {
            return "none";
        }
    }

    /**
     * Get edit permission.
     *
     * This method determines the user's permission to edit content.
     *
     * @return string Returns "all", "own", "own_as_client" or "none" depending on the user's edit permission.
     */
    public static function getEditPermission($db): string
    {
        if (UserManager::verify_permission($db,'zone_content_edit_others')) {
            return "all";
        } elseif (UserManager::verify_permission($db,'zone_content_edit_own')) {
            return "own";
        } elseif (UserManager::verify_permission($db, 'zone_content_edit_own_as_client')) {
            return "own_as_client";
        } else {
            return "none";
        }
    }

    /**
     * Get permissions.
     *
     * This method checks a set of permissions for the user.
     *
     * @param PDOLayer $db The database connection.
     * @param array $permissions An array containing the permission keys to check.
     * @return array An associative array containing the permission key and its corresponding boolean value.
     */
    public static function getPermissions(PDOLayer $db, array $permissions): array
    {
        $result = [];

        foreach ($permissions as $permissionName) {
            $result[$permissionName] = UserManager::verify_permission($db, $permissionName);
        }

        return $result;
    }
}
