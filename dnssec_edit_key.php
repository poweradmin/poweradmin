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
 * Script that handles zone deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

global $pdnssec_use;

$zone_id = "-1";
if (isset($_GET['id']) && v_num($_GET['id'])) {
    $zone_id = $_GET['id'];
}

$key_id = "-1";
if (isset($_GET['key_id']) && v_num($_GET['key_id'])) {
    $key_id = (int) $_GET['key_id'];
}

$confirm = "-1";
if (isset($_GET['confirm']) && v_num($_GET['confirm'])) {
    $confirm = $_GET['confirm'];
}

$user_is_zone_owner = verify_user_is_owner_zoneid($zone_id);

if ($zone_id == "-1") {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;
}

$domain_name = get_zone_name_from_id($zone_id);

if ($key_id == "-1") {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;
}

if (!dnssec_zone_key_exists($domain_name, $key_id)) {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;
}

$key_info = dnssec_get_zone_key($domain_name, $key_id);
if ($key_info[5]) {
    echo "     <h2>" . _('Deactivate zone key') . "</h2>\n";
} else {
    echo "     <h2>" . _('Activate zone key') . "</h2>\n";
}

if ($confirm == '1') {
    if ($key_info[5]) {
        if (dnssec_deactivate_zone_key($domain_name, $key_id)) {
            success(SUC_EXEC_PDNSSEC_DEACTIVATE_ZONE_KEY);
        }
    } else {
        if (dnssec_activate_zone_key($domain_name, $key_id)) {
            success(SUC_EXEC_PDNSSEC_ACTIVATE_ZONE_KEY);
        }
    }
} else {
    if ($user_is_zone_owner == "1") {
        echo "      " . _('Domain') . ": " . $domain_name . "<br>\n";
        echo "      " . _('Id') . ": " . $key_info[0] . "<br>\n";
        echo "      " . _('Type') . ": " . $key_info[1] . "<br>\n";
        echo "      " . _('Tag') . ": " . $key_info[2] . "<br>\n";
        echo "      " . _('Algorithm') . ": " . dnssec_algorithm_to_name($key_info[3]) . "<br>\n";
        echo "      " . _('Bits') . ": " . $key_info[4] . "<br>\n";
        echo "      " . _('Active') . ": " . ($key_info[5] ? _('Yes') : _('No')) . "\n";
        echo "     <p>" . _('Are you sure?') . "</p>\n";
        echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='dnssec_edit_key.php?id=" . $zone_id . "&amp;key_id=$key_id&amp;confirm=1'\" value=\"" . _('Yes') . "\">\n";
        echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='index.php'\" value=\"" . _('No') . "\">\n";
    } else {
        error(ERR_PDNSSEC_DEL_ZONE_KEY);
    }
}

include_once("inc/footer.inc.php");
