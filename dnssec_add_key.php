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
 * Script that handles requests to add new supermaster servers
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

$zone_id = "-1";
if (isset($_GET['id']) && v_num($_GET['id'])) {
    $zone_id = $_GET['id'];
}

$user_is_zone_owner = verify_user_is_owner_zoneid($zone_id);

if ($user_is_zone_owner == "0") {
    error(ERR_PERM_VIEW_ZONE);
    include_once("inc/footer.inc.php");
    exit();
}

if (zone_id_exists($zone_id) == "0") {
    error(ERR_ZONE_NOT_EXIST);
    include_once("inc/footer.inc.php");
    exit();
}

$key_type = "";
if (isset($_POST['key_type'])) {
    $key_type = $_POST['key_type'];

    if ($key_type != 'ksk' && $key_type != 'zsk') {
        error(ERR_INV_INPUT);
        include_once("inc/footer.inc.php");
        exit;
    }
}

$bits = "";
if (isset($_POST["bits"])) {
    $bits = $_POST["bits"];

    $valid_values = array('2048', '1024', '768', '384', '256');
    if (!in_array($bits, $valid_values)) {
        error(ERR_INV_INPUT);
        include_once("inc/footer.inc.php");
        exit;
    }
}

$algorithm = "";
if (isset($_POST["algorithm"])) {
    $algorithm = $_POST["algorithm"];

    $valid_algorithm = array('rsasha1', 'rsasha256', 'rsasha512', 'gost', 'ecdsa256', 'ecdsa384');
    if (!in_array($algorithm, $valid_algorithm)) {
        error(ERR_INV_INPUT);
        include_once("inc/footer.inc.php");
        exit;
    }
}

$domain_name = get_zone_name_from_id($zone_id);
if (isset($_POST["submit"])) {
    if (dnssec_add_zone_key($domain_name, $key_type, $bits, $algorithm)) {
        success(SUC_EXEC_PDNSSEC_ADD_ZONE_KEY);
    } else {
        error(ERR_EXEC_PDNSSEC_ADD_ZONE_KEY);
    }
}

echo "     <h2>" . _('Add key for zone '). $domain_name . "</h2>\n";

echo "     <form method=\"post\" action=\"dnssec_add_key.php?id=".$zone_id."\">\n";
echo "      <table>\n";
echo "       <tr>\n";
echo "        <td width=\"100\" class=\"n\">" . _('Key type') . "</td>\n";
echo "        <td class=\"n\">\n";
echo "         <select name=\"key_type\">\n";
echo "          <option value=\"\"></option>\n";
echo "          <option value=\"ksk\">KSK</option>\n";
echo "          <option value=\"zsk\">ZSK</option>\n";
echo "         </select>\n";
echo "        </td>\n";
echo "       </tr>\n";
echo "       <tr>\n";
echo "        <td class=\"n\">" . _('Bits in length') . "</td>\n";
echo "        <td class=\"n\">\n";
echo "         <select name=\"bits\">\n";
echo "          <option value=\"\"></option>\n";
echo "          <option value=\"2048\">2048</option>\n";
echo "          <option value=\"1024\">1024</option>\n";
echo "          <option value=\"768\">768</option>\n";
echo "          <option value=\"384\">384</option>\n";
echo "          <option value=\"256\">256</option>\n";
echo "         </select>\n";
echo "        </td>\n";
echo "       </tr>\n";
echo "       <tr>\n";
echo "        <td class=\"n\">" . _('Algorithm') . "</td>\n";
echo "        <td class=\"n\">\n";

echo "         <select name=\"algorithm\">\n";
echo "          <option value=\"\"></option>\n";
echo "          <option value=\"rsasha1\">".dnssec_shorthand_to_algorithm_name('rsasha1')."</option>\n";
echo "          <option value=\"rsasha256\">".dnssec_shorthand_to_algorithm_name('rsasha256')."</option>\n";
echo "          <option value=\"rsasha512\">".dnssec_shorthand_to_algorithm_name('rsasha512')."</option>\n";
echo "          <option value=\"gost\">".dnssec_shorthand_to_algorithm_name('gost')."</option>\n";
echo "          <option value=\"ecdsa256\">".dnssec_shorthand_to_algorithm_name('ecdsa256')."</option>\n";
echo "          <option value=\"ecdsa384\">".dnssec_shorthand_to_algorithm_name('ecdsa384')."</option>\n";
echo "         </select>\n";

echo "        </td>\n";
echo "       </tr>\n";
echo "       <tr>\n";
echo "        <td class=\"n\">&nbsp;</td>\n";
echo "        <td class=\"n\">\n";
echo "         <input type=\"submit\" class=\"button\" name=\"submit\" value=\"" . _('Add key') . "\">\n";
echo "        </td>\n";
echo "       </tr>\n";
echo "      </table>\n";
echo "     </form>\n";

include_once("inc/footer.inc.php");
