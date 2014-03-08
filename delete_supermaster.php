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
 * Script that handles deletion of supermasters
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

$master_ip = "-1";
if (isset($_GET['master_ip']) && (is_valid_ipv4($_GET['master_ip']) || is_valid_ipv6($_GET['master_ip']))) {
    $master_ip = $_GET['master_ip'];
}

$ns_name = "-1";
if (isset($_GET['ns_name']) && (is_valid_hostname_fqdn($_GET['ns_name'], 0))) {
    $ns_name = $_GET['ns_name'];
}

$confirm = "-1";
if ((isset($_GET['confirm'])) && (v_num($_GET['confirm']))) {
    $confirm = $_GET['confirm'];
}

if ($master_ip == "-1" || $ns_name == "-1") {
    error(ERR_INV_INPUT);
} else {
    (verify_permission('supermaster_edit')) ? $perm_sm_edit = "1" : $perm_sm_edit = "0";
    if ($perm_sm_edit == "0") {
        error(ERR_PERM_DEL_SM);
    } else {
        $info = get_supermaster_info_from_ip($master_ip);

        echo "     <h2>" . _('Delete supermaster') . " \"" . $master_ip . "\"</h2>\n";

        if (isset($_GET['confirm']) && $_GET["confirm"] == '1') {
            if (!supermaster_ip_name_exists($master_ip, $ns_name)) {
                header("Location: list_supermasters.php");
                exit;
            }

            if (delete_supermaster($master_ip, $ns_name)) {
                success(SUC_SM_DEL);
            }
        } else {
            echo "     <p>\n";
            echo "      " . _('Hostname in NS record') . ": " . $info['ns_name'] . "<br>\n";
            echo "      " . _('Account') . ": " . $info['account'] . "\n";
            echo "     </p>\n";
            echo "     <p>" . _('Are you sure?') . "</p>\n";
            echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='delete_supermaster.php?master_ip=" . $master_ip . "&amp;ns_name=" . $info['ns_name'] . "&amp;confirm=1'\" value=\"" . _('Yes') . "\">\n";
            echo "     <input type=\"button\" class=\"button\" OnClick=\"location.href='index.php'\" value=\"" . _('No') . "\">\n";
        }
    }
}

include_once("inc/footer.inc.php");
