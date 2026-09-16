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

namespace PoweradminInstall;

use Poweradmin\Domain\Model\Permission;

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
            [41, Permission::PERM_ZONE_MASTER_ADD, 'User is allowed to add new master zones.'],
            [42, Permission::PERM_ZONE_SLAVE_ADD, 'User is allowed to add new slave zones.'],
            [43, Permission::PERM_ZONE_CONTENT_VIEW_OWN, 'User is allowed to see the content of zones he owns.'],
            [44, Permission::PERM_ZONE_CONTENT_EDIT_OWN, 'User is allowed to edit the content of zones he owns.'],
            [45, Permission::PERM_ZONE_META_EDIT_OWN, 'User is allowed to edit the meta data of zones he owns.'],
            [46, Permission::PERM_ZONE_CONTENT_VIEW_OTHERS, 'User is allowed to see the content of zones he does not own.'],
            [47, Permission::PERM_ZONE_CONTENT_EDIT_OTHERS, 'User is allowed to edit the content of zones he does not own.'],
            [48, Permission::PERM_ZONE_META_EDIT_OTHERS, 'User is allowed to edit the meta data of zones he does not own.'],
            [49, Permission::PERM_SEARCH, 'User is allowed to perform searches.'],
            [50, Permission::PERM_SUPERMASTER_VIEW, 'User is allowed to view supermasters.'],
            [51, Permission::PERM_SUPERMASTER_ADD, 'User is allowed to add new supermasters.'],
            [52, Permission::PERM_SUPERMASTER_EDIT, 'User is allowed to edit supermasters.'],
            [53, Permission::PERM_USER_IS_UEBERUSER, 'User has full access. God-like. Redeemer.'],
            [54, Permission::PERM_USER_VIEW_OTHERS, 'User is allowed to see other users and their details.'],
            [55, Permission::PERM_USER_ADD_NEW, 'User is allowed to add new users.'],
            [56, Permission::PERM_USER_EDIT_OWN, 'User is allowed to edit their own details.'],
            [57, Permission::PERM_USER_EDIT_OTHERS, 'User is allowed to edit other users.'],
            [58, Permission::PERM_USER_PASSWD_EDIT_OTHERS, 'User is allowed to edit the password of other users.'], // not used
            [59, Permission::PERM_USER_EDIT_TEMPL_PERM, 'User is allowed to change the permission template that is assigned to a user.'],
            [60, Permission::PERM_TEMPL_PERM_ADD, 'User is allowed to add new permission templates.'],
            [61, Permission::PERM_TEMPL_PERM_EDIT, 'User is allowed to edit existing permission templates.'],
            [62, Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT, 'User is allowed to edit record, but not SOA and NS.'],
            [63, Permission::PERM_ZONE_TEMPL_ADD, 'User is allowed to add new zone templates.'],
            [64, Permission::PERM_ZONE_TEMPL_EDIT, 'User is allowed to edit existing zone templates.'],
            [65, Permission::PERM_API_MANAGE_KEYS, 'User is allowed to create and manage API keys.'],
            [67, Permission::PERM_ZONE_DELETE_OWN, 'User is allowed to delete zones they own.'],
            [68, Permission::PERM_ZONE_DELETE_OTHERS, 'User is allowed to delete zones owned by others.'],
            [69, Permission::PERM_USER_ENFORCE_MFA, 'User is required to use multi-factor authentication.'],
            [70, Permission::PERM_ZONE_DNSSEC_MANAGE_OWN, 'User is allowed to manage DNSSEC keys for zones he owns.'],
            [71, Permission::PERM_ZONE_LOGS_VIEW_OWN, 'User is allowed to view activity logs for zones he owns.'],
            [72, Permission::PERM_ZONE_LOGS_VIEW_OTHERS, 'User is allowed to view activity logs for zones he does not own.'],
            [73, Permission::PERM_USER_LOGS_VIEW, 'User is allowed to view the user activity logs.'],
            [74, Permission::PERM_GROUP_LOGS_VIEW, 'User is allowed to view the group activity logs.'],
            [75, Permission::PERM_EDIT_NS_SUBZONE, 'User is allowed to edit NS records below the zone apex, but not SOA and apex NS records.'],
            [76, Permission::PERM_ZONE_METADATA_VIEW_OWN, 'User is allowed to see the meta data of zones he owns.'],
            [77, Permission::PERM_ZONE_METADATA_VIEW_OTHERS, 'User is allowed to see the meta data of zones he does not own.'],
            [78, Permission::PERM_ZONE_OWNERSHIP_VIEW_OWN, 'User is allowed to see the owners of zones he owns.'],
            [79, Permission::PERM_ZONE_OWNERSHIP_VIEW_OTHERS, 'User is allowed to see the owners of zones he does not own.'],
        ];
    }
}
