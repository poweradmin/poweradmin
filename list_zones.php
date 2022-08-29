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
 * Script that displays zone list
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\DnsRecord;

require_once 'inc/toolkit.inc.php';
require_once 'inc/pagination.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$pdnssec_use = $app->config('pdnssec_use');
$iface_zonelist_serial = $app->config('iface_zonelist_serial');
$iface_rowamount = $app->config('iface_rowamount');

if (isset($_GET["start"])) {
    define('ROWSTART', (($_GET["start"] - 1) * $iface_rowamount));
} else {
    define('ROWSTART', 0);
}

if (do_hook('verify_permission', 'zone_content_view_others')) {
    $perm_view = "all";
} elseif (do_hook('verify_permission', 'zone_content_view_own')) {
    $perm_view = "own";
} else {
    $perm_view = "none";
}

if (do_hook('verify_permission', 'zone_content_edit_others')) {
    $perm_edit = "all";
} elseif (do_hook('verify_permission', 'zone_content_edit_own')) {
    $perm_edit = "own";
} else {
    $perm_edit = "none";
}

if (isset($_GET["letter"])) {
    define('LETTERSTART', $_GET["letter"]);
    $_SESSION["letter"] = $_GET["letter"];
} elseif (isset($_SESSION["letter"])) {
    define('LETTERSTART', $_SESSION["letter"]);
} else {
    define('LETTERSTART', "a");
}

$count_zones_all = DnsRecord::zone_count_ng("all");
$count_zones_all_letterstart = DnsRecord::zone_count_ng($perm_view, LETTERSTART);
$count_zones_view = DnsRecord::zone_count_ng($perm_view);
$count_zones_edit = DnsRecord::zone_count_ng($perm_edit);

if (isset($_GET["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_GET["zone_sort_by"]);
    $_SESSION["list_zone_sort_by"] = $_GET["zone_sort_by"];
} elseif (isset($_POST["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_POST["zone_sort_by"]);
    $_SESSION["list_zone_sort_by"] = $_POST["zone_sort_by"];
} elseif (isset($_SESSION["list_zone_sort_by"])) {
    define('ZONE_SORT_BY', $_SESSION["list_zone_sort_by"]);
} else {
    define('ZONE_SORT_BY', "name");
}

$zone_sort_by = ZONE_SORT_BY;
if (!in_array(ZONE_SORT_BY, array('name', 'type', 'count_records', 'owner'))) {
    $zone_sort_by = 'name';
}

echo "    <h4 class=\"mb-3\">" . _('List zones') . "</h2>\n";
echo "    <div class=\"text-secondary\">" . _('Total number of zones:') . " " . $count_zones_all . "</div>\n";

if ($perm_view == "none") {
    echo "     <p>" . _('You do not have the permission to see any zones.') . "</p>\n";
} elseif (($count_zones_view > $iface_rowamount && $count_zones_all_letterstart == "0") || $count_zones_view == 0) {
    if ($count_zones_view > $iface_rowamount) {
        echo "<div class=\"showmax\">";
        show_letters(LETTERSTART, $_SESSION["userid"]);
        echo "</div>";
    }
    echo "     <p>" . _('There are no zones to show in this listing.') . "</p>\n";
} else {
    if (LETTERSTART != 'all') {
        echo "     <div class=\"showmax\">\n";
        show_pages($count_zones_all_letterstart, $iface_rowamount);
        echo "     </div>\n";
    }

    if ($count_zones_view > $iface_rowamount) {
        echo "<div class=\"showmax\">";
        show_letters(LETTERSTART, $_SESSION["userid"]);
        echo "</div>";
    }
    echo "     <form method=\"post\" action=\"delete_domains.php\">\n";
    echo "     <table class=\"table table-striped table-hover\">\n";
    echo "     <thead>\n";
    echo "      <tr>\n";
    echo "       <th><input type=\"checkbox\" id=\"select_zones\" onClick=\"toggleZoneCheckboxes()\" /></th>\n";
    echo "       <th>&nbsp;</th>\n";
    echo "       <th><a href=\"list_zones.php?zone_sort_by=name\">" . _('Name') . "</a></th>\n";
    echo "       <th><a href=\"list_zones.php?zone_sort_by=type\">" . _('Type') . "</a></th>\n";
    echo "       <th><a href=\"list_zones.php?zone_sort_by=count_records\">" . _('Records') . "</a></th>\n";
    echo "       <th><a href=\"list_zones.php?zone_sort_by=owner\">" . _('Owner') . "</a></th>\n";

    if ($iface_zonelist_serial) {
        echo "       <th>" . _('Serial') . "</th>\n";
    }

    if ($pdnssec_use) {
        echo "       <th>" . _('DNSSEC') . "</th>\n";
    }

    echo "      </tr>\n";
    echo "    </thead>\n";

    if ($count_zones_view <= $iface_rowamount) {
        $zones = DnsRecord::get_zones($perm_view, $_SESSION['userid'], "all", ROWSTART, $iface_rowamount, $zone_sort_by);
    } elseif (LETTERSTART == 'all') {
        $zones = DnsRecord::get_zones($perm_view, $_SESSION['userid'], "all", ROWSTART, 'all', $zone_sort_by);
    } else {
        $zones = DnsRecord::get_zones($perm_view, $_SESSION['userid'], LETTERSTART, ROWSTART, $iface_rowamount, $zone_sort_by);
        $count_zones_shown = ($zones == -1) ? 0 : count($zones);
    }
    echo "       <tbody>\n";
    foreach ($zones as $zone) {
        if ($zone['count_records'] == NULL) {
            $zone['count_records'] = 0;
        }

        if ($iface_zonelist_serial) {
            $serial = DnsRecord::get_serial_by_zid($zone['id']);
        }

        if ($perm_edit != "all" || $perm_edit != "none") {
            $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone["id"]);
        }
        echo "         <tr>\n";
        echo "          <td class=\"checkbox\">\n";
        if ($count_zones_edit > 0 && ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1"))) {
            echo "       <input type=\"checkbox\" name=\"zone_id[]\" value=\"" . $zone['id'] . "\">";
        }
        echo "          </td>\n";
        echo "          <td class=\"name\">" . idn_to_utf8($zone["name"], IDNA_NONTRANSITIONAL_TO_ASCII) . "</td>\n";
        echo "          <td class=\"type\">" . strtolower($zone["type"]) . "</td>\n";
        echo "          <td class=\"count\">" . $zone["count_records"] . "</td>\n";
        echo "          <td class=\"owner\">" . $zone["owner"] . "</td>\n";
        if ($iface_zonelist_serial) {
            if ($serial != "") {
                echo "          <td class=\"y\">" . $serial . "</td>\n";
            } else {
                echo "          <td class=\"n\">&nbsp;</td>\n";
            }
        }
        if ($pdnssec_use) {
            echo "          <td class=\"dnssec\"><input type=\"checkbox\" onclick=\"return false\" " . ($zone["secured"] ? 'checked' : '') . "></td>\n";
        }
        echo "          <td>\n";
        echo "           <a class=\"btn btn-outline-primary btn-sm\" href=\"edit.php?name=" . $zone['name'] . "&id=" . $zone['id'] . "\"><i class=\"bi bi-eye\"></i> " . _('View zone') . "</a>\n";
        if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
            echo "           <a class=\"btn btn-outline-primary btn-sm\" href=\"delete_domain.php?name=" . $zone['name'] . "&id=" . $zone["id"] . "\"><i class=\"bi bi-trash\"></i>" . _('Delete zone') . "</a>\n";
        }
        echo "          </td>\n";
        echo "           </tr>\n";
    }
    echo "          </tbody>\n";
    echo "        </table>\n";
    echo "      <input type=\"submit\" name=\"commit\" value=\"" . _('Delete zone(s)') . "\" class=\"btn btn-primary\">\n";
    echo "     </form>\n";
}

include_once('inc/footer.inc.php');
