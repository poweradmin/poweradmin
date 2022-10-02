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
 * Script that displays list of zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
include_once 'inc/header.inc.php';

$app = AppFactory::create();

$perm_zone_master_add = do_hook('verify_permission', 'zone_master_add');

if (!$perm_zone_master_add) {
    error(ERR_PERM_EDIT_ZONE_TEMPL);
    include_once('inc/footer.inc.php');
    exit;
}

$app->render('list_zone_templ.html', [
    'perm_zone_master_add' => $perm_zone_master_add,
    'user_name' => do_hook('get_fullname_from_userid', $_SESSION['userid']) ?: $_SESSION['userlogin'],
    'zone_templates' => ZoneTemplate::get_list_zone_templ($_SESSION['userid'])
]);

include_once('inc/footer.inc.php');
