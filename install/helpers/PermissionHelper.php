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

namespace PoweradminInstall;

class PermissionHelper
{
    /**
     * Get the permission mappings.
     *
     * @return array
     */
    public static function getPermissionMappings(): array
    {
        return [
            [41, 'zone_master_add', 'User is allowed to add new master zones.'],
            [42, 'zone_slave_add', 'User is allowed to add new slave zones.'],
            [43, 'zone_content_view_own', 'User is allowed to see the content and meta data of zones he owns.'],
            [44, 'zone_content_edit_own', 'User is allowed to edit the content of zones he owns.'],
            [45, 'zone_meta_edit_own', 'User is allowed to edit the meta data of zones he owns.'],
            [46, 'zone_content_view_others', 'User is allowed to see the content and meta data of zones he does not own.'],
            [47, 'zone_content_edit_others', 'User is allowed to edit the content of zones he does not own.'],
            [48, 'zone_meta_edit_others', 'User is allowed to edit the meta data of zones he does not own.'],
            [49, 'search', 'User is allowed to perform searches.'],
            [50, 'supermaster_view', 'User is allowed to view supermasters.'],
            [51, 'supermaster_add', 'User is allowed to add new supermasters.'],
            [52, 'supermaster_edit', 'User is allowed to edit supermasters.'],
            [53, 'user_is_ueberuser', 'User has full access. God-like. Redeemer.'],
            [54, 'user_view_others', 'User is allowed to see other users and their details.'],
            [55, 'user_add_new', 'User is allowed to add new users.'],
            [56, 'user_edit_own', 'User is allowed to edit their own details.'],
            [57, 'user_edit_others', 'User is allowed to edit other users.'],
            [58, 'user_passwd_edit_others', 'User is allowed to edit the password of other users.'], // not used
            [59, 'user_edit_templ_perm', 'User is allowed to change the permission template that is assigned to a user.'],
            [60, 'templ_perm_add', 'User is allowed to add new permission templates.'],
            [61, 'templ_perm_edit', 'User is allowed to edit existing permission templates.'],
            [62, 'zone_content_edit_own_as_client', 'User is allowed to edit record, but not SOA and NS.'],
        ];
    }
}
