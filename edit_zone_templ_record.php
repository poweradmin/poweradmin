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
 * Script that handles records editing in zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\RecordType;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();

$record_id = "-1";
if (isset($_GET['id']) && Validation::is_number($_GET['id'])) {
    $record_id = htmlspecialchars($_GET['id']);
}

$zone_templ_id = "-1";
if (isset($_GET['zone_templ_id']) && Validation::is_number($_GET['zone_templ_id'])) {
    $zone_templ_id = htmlspecialchars($_GET['zone_templ_id']);
}

$owner = ZoneTemplate::get_zone_templ_is_owner($zone_templ_id, $_SESSION['userid']);

if (isset($_POST["commit"])) {
    if (!(do_hook('verify_permission', 'zone_master_add')) || !$owner) {
        error(ERR_PERM_EDIT_RECORD);
    } else {
        $ret_val = ZoneTemplate::edit_zone_templ_record($_POST);
        if ($ret_val == "1") {
            success(SUC_RECORD_UPD);
        } else {
            echo "     <div class=\"alert alert-danger\">" . $ret_val . "</div>\n";
        }
    }
}

$templ_details = ZoneTemplate::get_zone_templ_details($zone_templ_id);
if (!(do_hook('verify_permission', 'zone_master_add')) || !$owner) {
    error(ERR_PERM_VIEW_RECORD);
    include_once("inc/footer.inc.php");
    exit;
}

$record = ZoneTemplate::get_zone_templ_record_from_id($record_id);

$app->render('edit_zone_templ_record.html', [
    'record' => $record,
    'zone_templ_id' => $zone_templ_id,
    'record_id' => $record_id,
    'templ_details' => $templ_details,
    'record_types' => RecordType::getTypes(),
]);

include_once("inc/footer.inc.php");
