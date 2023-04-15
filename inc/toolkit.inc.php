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

include_once 'config-me.inc.php';

require_once 'messages.inc.php';

if (!@include_once('config.inc.php')) {
    if (!file_exists('install')) {
        error(_('You have to create a config.inc.php!'));
    }
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

require_once 'benchmark.php';

session_start();

// Database connection
require_once 'database.inc.php';
require_once 'plugin.inc.php';
require_once 'i18n.inc.php';

add_listener('authenticate', 'authenticate_local');
add_listener('verify_permission', 'verify_permission_local');
add_listener('show_users', 'show_users_local');
add_listener('change_user_pass', 'change_user_pass_local');
add_listener('list_permission_templates', 'list_permission_templates_local');
add_listener('is_valid_user', 'is_valid_user_local');
add_listener('delete_user', 'delete_user_local');
add_listener('delete_perm_templ', 'delete_perm_templ_local');
add_listener('edit_user', 'edit_user_local');
add_listener('get_fullname_from_userid', 'get_fullname_from_userid_local');
add_listener('get_owner_from_id', 'get_owner_from_id_local');
add_listener('get_fullnames_owners_from_domainid', 'get_fullnames_owners_from_domainid_local');
add_listener('verify_user_is_owner_zoneid', 'verify_user_is_owner_zoneid_local');
add_listener('get_user_detail_list', 'get_user_detail_list_local');
add_listener('get_permissions_by_template_id', 'get_permissions_by_template_id_local');
add_listener('get_permission_template_details', 'get_permission_template_details_local');
add_listener('add_perm_templ', 'add_perm_templ_local');
add_listener('update_perm_templ_details', 'update_perm_templ_details_local');
add_listener('update_user_details', 'update_user_details_local');
add_listener('add_new_user', 'add_new_user_local');

use Poweradmin\DependencyCheck;
DependencyCheck::verifyExtensions();

global $db_host, $db_port, $db_user, $db_pass, $db_name, $db_charset, $db_collation, $db_type;

$databaseCredentials = [
    'db_host' => $db_host,
    'db_port' => $db_port,
    'db_user' => $db_user,
    'db_pass' => $db_pass,
    'db_name' => $db_name,
    'db_charset' => $db_charset,
    'db_collation' => $db_collation,
    'db_type' => $db_type,
];

if ($databaseCredentials['db_type'] == 'sqlite') {
    $databaseCredentials['db_file'] = $databaseCredentials['db_name'];
}

$db = dbConnect($databaseCredentials);

do_hook('authenticate');
