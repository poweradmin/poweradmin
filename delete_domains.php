<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
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
 * Script that handles zones deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

if (verify_permission('zone_content_edit_others')) {
    $perm_edit = "all";
} elseif (verify_permission('zone_content_edit_own')) {
    $perm_edit = "own";
} else {
    $perm_edit = "none";
}

$confirm = "-1";
if (isset($_POST['confirm'])) {
    $confirm = "1";
}

$zones = $_POST['zone_id'];
if (!$zones) {
    header("Location: list_zones.php");
    exit;
}

echo "     <h2>" . _('Delete zones') . "</h2>\n";

if ($confirm == '1') {
    //Fetch information about zones before deleting them
    $deleted_zones = array();
    foreach ($zones as $zone) {
        $zone_info = get_zone_info_from_id($zone);
        array_push($deleted_zones,$zone_info);
    }
    $delete_domains = delete_domains($zones);
    if ($delete_domains) {
        count($deleted_zones) == 1 ? success(SUC_ZONE_DEL) : success(SUC_ZONES_DEL);
        //Zones successfully deleted so generate log messages from information retrieved earlier
        foreach ($deleted_zones as $zone_info) {
            log_info(sprintf('client_ip:%s user:%s operation:delete_zone zone:%s zone_type:%s',
                              $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                              $zone_info['name'], $zone_info['type']));
        }
    }
} else {
    echo "     <form method=\"post\" action=\"delete_domains.php\">\n";
    foreach ($zones as $zone) {
        $zone_owners = get_fullnames_owners_from_domainid($zone);
        $user_is_zone_owner = verify_user_is_owner_zoneid($zone);
        $zone_info = get_zone_info_from_id($zone);
        if ($perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1")) {
            echo "      <input type=\"hidden\" name=\"zone_id[]\" value=\"" . $zone . "\">\n";
            echo "      " . _('Name') . ": " . $zone_info['name'] . "<br>\n";
            echo "      " . _('Owner') . ": " . $zone_owners . "<br>\n";
            echo "      " . _('Type') . ": " . $zone_info['type'] . "\n";
            if ($zone_info['type'] == "SLAVE") {
                $slave_master = get_domain_slave_master($zone);
                if (supermaster_exists($slave_master)) {
                    echo "        <p>         \n";
                    printf(_('You are about to delete a slave zone of which the master nameserver, %s, is a supermaster. Deleting the zone now, will result in temporary removal only. Whenever the supermaster sends a notification for this zone, it will be added again!'), $slave_master);
                    echo "        </p>\n";
                }
            }
            echo "     <br><br>\n";
        } else {
            error(ERR_PERM_DEL_ZONE);
        }
    }
    echo "                     <p>" . _('Are you sure?') . "</p>\n";
    echo "                     <input type=\"submit\" name=\"confirm\" value=\"" . _('Yes') . "\" class=\"button\">\n";
    echo "                     <input type=\"button\" class=\"button\" OnClick=\"location.href='list_zones.php'\" value=\"" . _('No') . "\">\n";
    echo "     </form>\n";
}

include_once("inc/footer.inc.php");
