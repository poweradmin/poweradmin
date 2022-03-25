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

/**
 * Script that handles requests to update and list users
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;

require_once 'inc/toolkit.inc.php';
include_once 'inc/header.inc.php';

$app = AppFactory::create();

if (isset ($_POST['commit'])) {
    foreach ($_POST['user'] as $user) {
        do_hook('update_user_details', $user);
    }
}

$app->render('users.html', [
    'perm_view_others' => do_hook('verify_permission', 'user_view_others'),
    'perm_edit_own' => do_hook('verify_permission', 'user_edit_own'),
    'perm_edit_others' => do_hook('verify_permission', 'user_edit_others'),
    'perm_templ_perm_edit' => do_hook('verify_permission', 'templ_perm_edit'),
    'perm_is_godlike' => do_hook('verify_permission', 'user_is_ueberuser'),
    'perm_add_new' => do_hook('verify_permission', 'user_add_new'),
    'users' => do_hook('get_user_detail_list', ""),
    'perm_templates' => do_hook('list_permission_templates'),
    'ldap_use' => $app->config('ldap_use'),
    'session_userid' => $_SESSION ["userid"]
]);

include_once('inc/footer.inc.php');
