<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2015  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Script that handles creating RFCs
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2015 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");
include_once("inc/Rfc.class.php");
require_once("inc/RfcPermissions.class.php");

$record_id_in = null;
if (isset($_GET['record_id']) && v_num($_GET['record_id'])) {
    $record_id_in = $_GET['record_id'];
}

$zone_id_in = null;
if (isset($_GET['zone_id']) && v_num($_GET['zone_id'])) {
    $zone_id_in = $_GET['zone_id'];
}

$action_in = null;
if (isset($_GET['action']) && v_str($_GET['action'])) {
    $action_in = $_GET['action'];
}


global $db;
switch($action_in) {

    case "delete_record":
        # Stop on permission error
        if(!RfcPermissions::can_create_rfc($zone_id_in)) {
            error(ERR_RFC_PERMISSIONS);
            include_once("inc/footer.inc.php");
            exit();
        }

        $zone_id     = get_zone_id_from_record_id($record_id_in);
        $zone_serial = get_serial_by_zid($zone_id);
        $record      = get_record_from_id($record_id_in);
        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);

        if($zone_serial === false || $record === -1) {
            break;
        }

        $rfc = RfcBuilder::make()->myself()->now()->build();
        $old_record = new Record($record);

        $rfc->add_delete($zone_id, $zone_serial, $old_record);
        $rfc->write($db);
        success(SUC_RFC_CREATED);
        break;

    case "delete_zone":
        # Stop on permission error
        if(!RfcPermissions::can_create_rfc($zone_id_in)) {
            error(ERR_RFC_PERMISSIONS);
            include_once("inc/footer.inc.php");
            exit();
        }

        $zone_serial = get_serial_by_zid($zone_id_in);

        $rfc = RfcBuilder::make()->myself()->now()->build();
        $rfc->add_delete_domain($zone_id_in, $zone_serial);
        $rfc->write($db);

        success(SUC_RFC_CREATED);

        break;

    default:
        break;
}

include_once("inc/footer.inc.php");
