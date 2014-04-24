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
 * Script that handles requests to add new slave zone
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

global $dns_third_level_check;

$owner = "-1";
if ((isset($_POST['owner'])) && (v_num($_POST['owner']))) {
    $owner = $_POST['owner'];
}

$zone = "";
if (isset($_POST['domain'])) {
    $zone = trim($_POST['domain']);
}

$master = "";
if (isset($_POST['slave_master'])) {
    $master = $_POST['slave_master'];
}

$type = "SLAVE";

/*
  Check permissions
 */
(verify_permission('zone_slave_add')) ? $zone_slave_add = "1" : $zone_slave_add = "0";
(verify_permission('user_view_others')) ? $perm_view_others = "1" : $perm_view_others = "0";

if (isset($_POST['submit']) && $zone_slave_add == "1") {
    if (!is_valid_hostname_fqdn($zone, 0)) {
        error(ERR_DNS_HOSTNAME);
    } elseif ($dns_third_level_check && get_domain_level($zone) > 2 && domain_exists(get_second_level_domain($zone))) {
        error(ERR_DOMAIN_EXISTS);
    } elseif (domain_exists($zone) || record_name_exists($zone)) {
        error(ERR_DOMAIN_EXISTS);
    } elseif (!is_valid_ipv4($master, false) && !is_valid_ipv6($master)) {
        error(ERR_DNS_IP);
    } else {
        if (add_domain($zone, $owner, $type, $master, 'none')) {
            success("<a href=\"edit.php?id=" . get_zone_id_from_name($zone) . "\">" . SUC_ZONE_ADD . '</a>');
            log_info(sprintf('client_ip:%s user:%s operation:add_zone zone:%s zone_type:SLAVE zone_master:%s',
                              $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                              $zone, $master, $zone_template));
            unset($zone, $owner, $webip, $mailip, $empty, $type, $master);
        }
    }
}

if ($zone_slave_add != "1") {
    error(ERR_PERM_ADD_ZONE_SLAVE);
} else {
    echo "     <h2>" . _('Add slave zone') . "</h2>\n";

    $users = show_users();
    echo "     <form method=\"post\" action=\"add_zone_slave.php\">\n";
    echo "      <table>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('Zone name') . "</td>\n";
    echo "        <td class=\"n\">\n";
    echo "         <input type=\"text\" class=\"input\" name=\"domain\" value=\"\">\n";
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('IP address of master NS') . ":</td>\n";
    echo "        <td class=\"n\">\n";
    echo "         <input type=\"text\" class=\"input\" name=\"slave_master\" value=\"\">\n";
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">" . _('Owner') . ":</td>\n";
    echo "        <td class=\"n\">\n";
    echo "         <select name=\"owner\">\n";
    /*
      Display list of users to assign slave zone to if the
      editing user has the permissions to, otherise just
      display the adding users name
     */
    foreach ($users as $user) {
        if ($user['id'] === $_SESSION['userid']) {
            echo "          <option value=\"" . $user['id'] . "\" selected>" . $user['fullname'] . "</option>\n";
        } elseif ($perm_view_others == "1") {
            echo "          <option value=\"" . $user['id'] . "\">" . $user['fullname'] . "</option>\n";
        }
    }
    echo "         </select>\n";
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "       <tr>\n";
    echo "        <td class=\"n\">&nbsp;</td>\n";
    echo "        <td class=\"n\">\n";
    echo "         <input type=\"submit\" class=\"button\" name=\"submit\" value=\"" . _('Add zone') . "\">\n";
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "      </table>\n";
    echo "     </form>\n";
}

include_once("inc/footer.inc.php");
