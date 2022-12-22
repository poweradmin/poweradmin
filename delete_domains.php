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
 * Script that handles zones deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\DnsRecord;
use Poweradmin\Logger;
use Poweradmin\Permission;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$perm_edit = Permission::getEditPermission();

$zone_ids = $_POST['zone_id'];
if (!$zone_ids) {
    header("Location: list_zones.php");
    exit;
}

if (isset($_POST['confirm'])) {
    $deleted_zones = DnsRecord::get_zone_info_from_ids($zone_ids);
    $delete_domains = DnsRecord::delete_domains($zone_ids);

    if ($delete_domains) {
        count($deleted_zones) == 1 ? success(SUC_ZONE_DEL) : success(SUC_ZONES_DEL);

        foreach ($deleted_zones as $deleted_zone) {
            Logger::log_info(sprintf('client_ip:%s user:%s operation:delete_zone zone:%s zone_type:%s',
                $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                $deleted_zone['name'], $deleted_zone['type']), $deleted_zone['id']);
        }
    }

    include_once("inc/footer.inc.php");
    exit;
}

echo "     <h5 class=\"mb-3\">" . _('Delete zones') . "</h5>\n";
echo "     <form method=\"post\" action=\"delete_domains.php\">\n";
echo "<table class=\"table table-striped table-hover table-sm\">";
echo "<thead>";
echo "  <th> " . _('Name') . "</th>";
echo "  <th> " . _('Owner') . "</th>";
echo "  <th> " . _('Type') . "</th>";
echo "  <th> " . _('Note') . "</th>";
echo "</thead>";
echo "<tbody>";

$zones = [];
foreach ($zone_ids as $zone_id) {
    $zones[$zone_id] = DnsRecord::get_zone_info_from_id($zone_id);
    $zones[$zone_id]['owner'] = do_hook('get_fullnames_owners_from_domainid', $zone_id);
    $zones[$zone_id]['is_owner'] = $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);

    $zones[$zone_id]['has_supermaster'] = false;
    $zones[$zone_id]['slave_master'] = null;
    if ($zones[$zone_id]['type'] == "SLAVE") {
        $slave_master = DnsRecord::get_domain_slave_master($zone_id);
        $zones[$zone_id]['slave_master'] = $slave_master;
        if (DnsRecord::supermaster_exists($slave_master)) {
            $zones[$zone_id]['has_supermaster'] = true;
        }
    }
}

foreach ($zone_ids as $zone_id) {
    $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);

    echo "<tr>";
    echo "<input type=\"hidden\" name=\"zone_id[]\" value=\"" . htmlspecialchars($zone_id) . "\">\n";
    echo "<td>" . htmlspecialchars($zones[$zone_id]['name']) . "</td>\n";
    echo "<td>" . htmlspecialchars($zones[$zone_id]['owner']) . "</td>\n";
    echo "<td>" . htmlspecialchars($zones[$zone_id]['type']) . "\n";

    echo "        <td>\n";
    if ($perm_edit != "all" && ($perm_edit != "own" || $zones[$zone_id]['is_owner'] != "1")) {
        echo ERR_PERM_DEL_ZONE;
    } else if ($zones[$zone_id]['has_supermaster']) {
        echo _('You are about to delete a slave zone of which the master nameserver is a supermaster. Deleting the zone now, will result in temporary removal only. Whenever the supermaster sends a notification for this zone, it will be added again!');
    }
    echo "        </td>\n";
    echo "</tr>";
}

echo "</tbody>";
echo "</table>";
echo "                     <p>" . _('Are you sure?') . "</p>\n";
echo "                     <input class=\"btn btn-primary btn-sm\" type=\"submit\" name=\"confirm\" value=\"" . _('Yes') . "\">\n";
echo "                     <input class=\"btn btn-secondary btn-sm\"  type=\"button\" onClick=\"location.href='list_zones.php'\" value=\"" . _('No') . "\">\n";
echo "     </form>\n";

include_once("inc/footer.inc.php");
