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
 * Script that handles zone template deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();

if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
    error(ERR_INV_INPUT);
    include_once('inc/footer.inc.php');
    exit;
}

$zone_templ_id = $_GET['id'];

$owner = ZoneTemplate::get_zone_templ_is_owner($zone_templ_id, $_SESSION['userid']);
if (!do_hook('verify_permission', 'zone_master_add') || !$owner) {
    error(ERR_PERM_DEL_ZONE_TEMPL);
    include_once('inc/footer.inc.php');
    exit;
}

if (isset($_GET['confirm']) && Validation::is_number($_GET['confirm']) && $_GET["confirm"] == '1') {
    ZoneTemplate::delete_zone_templ($zone_templ_id);
    success(SUC_ZONE_TEMPL_DEL);
    include_once('inc/footer.inc.php');
    exit;
}

$templ_details = ZoneTemplate::get_zone_templ_details($zone_templ_id);
$app->render('delete_zone_templ.html', [
    'templ_name' => $templ_details['name'],
    'zone_templ_id' => $zone_templ_id,
]);

include_once('inc/footer.inc.php');
