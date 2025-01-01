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

namespace Poweradmin;

/**
 * Class Pages
 *
 * This class provides a static method to retrieve a list of page identifiers.
 *
 * @package Poweradmin
 */
class Pages
{
    /**
     * Get a list of page identifiers.
     *
     * This method returns an array of strings, each representing a page identifier
     * used in the Poweradmin application.
     *
     * @return array An array of page identifiers.
     */
    public static function getPages(): array
    {
        return [
            'add_perm_templ',
            'add_record',
            'add_supermaster',
            'add_user',
            'add_zone_master',
            'add_zone_slave',
            'add_zone_templ_record',
            'add_zone_templ',
            'bulk_registration',
            'change_password',
            'delete_domain',
            'delete_domains',
            'delete_perm_templ',
            'delete_record',
            'delete_supermaster',
            'delete_user',
            'delete_zone_templ',
            'delete_zone_templ_record',
            'dnssec_add_key',
            'dnssec',
            'dnssec_delete_key',
            'dnssec_ds_dnskey',
            'dnssec_edit_key',
            'edit_comment',
            'edit',
            'edit_perm_templ',
            'edit_record',
            'edit_user',
            'edit_zone_templ',
            'edit_zone_templ_record',
            'index',
            'list_log_users',
            'list_log_zones',
            'list_perm_templ',
            'list_supermasters',
            'list_zone_templ',
            'list_zones',
            'login',
            'logout',
            'migrations',
            'search',
            'users',
        ];
    }
}
