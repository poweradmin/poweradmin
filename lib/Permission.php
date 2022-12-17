<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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

namespace Poweradmin;

class Permission
{
    public static function getViewPermission(): string
    {
        if (do_hook('verify_permission', 'zone_content_view_others')) {
            return "all";
        } elseif (do_hook('verify_permission', 'zone_content_view_own')) {
            return "own";
        } else {
            return "none";
        }
    }

    public static function getEditPermission(): string
    {
        if (do_hook('verify_permission', 'zone_content_edit_others')) {
            return "all";
        } elseif (do_hook('verify_permission', 'zone_content_edit_own')) {
            return "own";
        } elseif (do_hook('verify_permission', 'zone_content_edit_own_as_client')) {
            return "own_as_client";
        } else {
            return "none";
        }
    }

    public static function getPermissions()
    {
        $arguments = func_get_args();
        $permissions = [];

        foreach ($arguments as $argument) {
            $permissions[$argument] = do_hook('verify_permission', $argument);
        }

        return $permissions;
    }
}
