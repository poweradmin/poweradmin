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
 * Script which displays available actions
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;

require 'vendor/autoload.php';

$app = AppFactory::create();
$dblog_use = $app->config('dblog_use');

require_once 'inc/toolkit.inc.php';
include_once 'inc/header.inc.php';

$template = sprintf("index_%s.html", $app->config('iface_index'));

$app->render($template, [
    'user_name' => $_SESSION["name"],
    'auth_used' => $_SESSION["auth_used"],
    'perm_search' => do_hook('verify_permission', 'search'),
    'perm_view_zone_own' => do_hook('verify_permission', 'zone_content_view_own'),
    'perm_view_zone_other' => do_hook('verify_permission', 'zone_content_view_others'),
    'perm_supermaster_view' => do_hook('verify_permission', 'supermaster_view'),
    'perm_zone_master_add' => do_hook('verify_permission', 'zone_master_add'),
    'perm_zone_slave_add' => do_hook('verify_permission', 'zone_slave_add'),
    'perm_supermaster_add' => do_hook('verify_permission', 'supermaster_add'),
    'perm_is_godlike' => do_hook('verify_permission', 'user_is_ueberuser'),
    'dblog_use' => $dblog_use
]);

include_once("inc/footer.inc.php");
