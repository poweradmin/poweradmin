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
 * Script that handles request to add new records to existing zone
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\DnsRecord;
use Poweradmin\Dnssec;
use Poweradmin\Permission;
use Poweradmin\RecordType;
use Poweradmin\Logger;
use Poweradmin\Validation;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$pdnssec_use = $app->config('pdnssec_use');
$iface_add_reverse_record = $app->config('iface_add_reverse_record');

$perm_view = Permission::getViewPermission();
$perm_edit = Permission::getEditPermission();

if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
    error(ERR_INV_INPUT);
    include_once('inc/footer.inc.php');
    exit;
}
$zone_id = htmlspecialchars($_GET['id']);

$ttl = $app->config('dns_ttl');
if (isset($_POST['ttl']) && Validation::is_number($_POST['ttl'])) {
    $ttl = $_POST['ttl'];
}

$prio = 10;
if (isset($_POST['prio']) && Validation::is_number($_POST['prio'])) {
    $prio = $_POST['prio'];
}

$name = $_POST['name'] ?? "";
$type = $_POST['type'] ?? "";
$content = $_POST['content'] ?? "";

$user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);
$zone_type = DnsRecord::get_domain_type($zone_id);
$zone_name = DnsRecord::get_domain_name_by_id($zone_id);

if (isset($_POST["commit"])) {
    if ($zone_type == "SLAVE" || $perm_edit == "none" || ($perm_edit == "own" || $perm_edit == "own_as_client") && !$user_is_zone_owner) {
        error(ERR_PERM_ADD_RECORD);
    } else {
        // a PTR-record is added if an A or an AAAA-record are created
        // and checkbox is checked

        if ((isset($_POST["reverse"])) && $iface_add_reverse_record) {
            if ($type === 'A') {
                $content_array = preg_split("/\./", $content);
                $content_rev = sprintf("%d.%d.%d.%d.in-addr.arpa", $content_array[3], $content_array[2], $content_array[1], $content_array[0]);
                $zone_rev_id = DnsRecord::get_best_matching_zone_id_from_name($content_rev);
            } elseif ($type === 'AAAA') {
                $content_rev = DnsRecord::convert_ipv6addr_to_ptrrec($content);
                $zone_rev_id = DnsRecord::get_best_matching_zone_id_from_name($content_rev);
            }
            if (isset($zone_rev_id) && $zone_rev_id != -1) {
                $zone_name = DnsRecord::get_domain_name_by_id($zone_id);
                $fqdn_name = sprintf("%s.%s", $name, $zone_name);
                if (DnsRecord::add_record($zone_rev_id, $content_rev, 'PTR', $fqdn_name, $ttl, $prio)) {
                    success(" <a href=\"edit.php?id=" . $zone_rev_id . "\"> " . _('The PTR-record was successfully added.') . "</a>");
                    Logger::log_info(sprintf('client_ip:%s user:%s operation:add_record record_type:PTR record:%s content:%s ttl:%s priority:%s',
                        $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                        $content_rev, $fqdn_name, $ttl, $prio), $zone_id);
                    if ($pdnssec_use && Dnssec::dnssec_rectify_zone($zone_rev_id)) {
                        success(SUC_EXEC_PDNSSEC_RECTIFY_ZONE);
                    }
                }
            } elseif (isset($content_rev)) {
                error(sprintf(ERR_REVERS_ZONE_NOT_EXIST, $content_rev));
            }
        }
        if (DnsRecord::add_record($zone_id, $name, $type, $content, $ttl, $prio)) {
            success(" <a href=\"edit.php?id=" . $zone_id . "\"> " . _('The record was successfully added.') . "</a>");
            Logger::log_info(sprintf('client_ip:%s user:%s operation:add_record record_type:%s record:%s.%s content:%s ttl:%s priority:%s',
                $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                $type, $name, $zone_name, $content, $ttl, $prio), $zone_id
            );

            if ($pdnssec_use && Dnssec::dnssec_rectify_zone($zone_id)) {
                success(SUC_EXEC_PDNSSEC_RECTIFY_ZONE);
            }

            $name = $type = $content = $ttl = $prio = "";
        }
    }
}

if ($zone_type == "SLAVE" || $perm_edit == "none" || ($perm_edit == "own" || $perm_edit == "own_as_client") && !$user_is_zone_owner) {
    error(ERR_PERM_ADD_RECORD);
    include_once('inc/footer.inc.php');
    exit;
}

echo "    <h5 class=\"mb-3\">" . _('Add record to zone') . "</h5>\n";
echo "     <form class=\"needs-validation\" method=\"post\" novalidate>\n";
echo "      <input type=\"hidden\" name=\"domain\" value=\"" . $zone_id . "\">\n";
echo "      <table class=\"table table-striped table-sm\">\n";
echo "       <tr>\n";
echo "        <td>" . _('Name') . "</td>\n";
echo "        <td>&nbsp;</td>\n";
echo "        <td>" . _('Type') . "</td>\n";
echo "        <td>" . _('Content') . "</td>\n";
echo "        <td>" . _('Priority') . "</td>\n";
echo "        <td>" . _('TTL') . "</td>\n";
echo "       </tr>\n";
echo "       <tr>\n";
echo "        <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"name\" value=\"" . htmlspecialchars($name) . "\">." . $zone_name . "</td>\n";
echo "        <td>IN</td>\n";
echo "        <td>\n";
echo "         <select class=\"form-select form-select-sm\" name=\"type\">\n";
$found_selected_type = !(isset($type) && $type);
foreach (RecordType::getTypes() as $record_type) {
    if (isset($type) && $type) {
        if ($type == $record_type) {
            $found_selected_type = true;
            $add = " SELECTED";
        } else {
            $add = "";
        }
    } else {
        if (preg_match('/i(p6|n-addr).arpa/i', $zone_name) && strtoupper($record_type) == 'PTR') {
            $add = " SELECTED";
            $rev = "";
        } elseif ((strtoupper($record_type) == 'A') && $iface_add_reverse_record) {
            $add = " SELECTED";
            $rev = "<input class=\"form-check-input\" type=\"checkbox\" name=\"reverse\"><span class=\"text-secondary\"> " . _('Add also reverse record') . "</span>\n";
        } else {
            $add = "";
        }
    }
    echo "          <option" . $add . " value=\"" . htmlspecialchars($record_type) . "\">" . $record_type . "</option>\n";
}
if (!$found_selected_type) {
    echo "          <option SELECTED value=\"" . htmlspecialchars($type) . "\"><i>" . htmlspecialchars($type) . "</i></option>\n";
}
echo "         </select>\n";
echo "        </td>\n";
echo "        <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"content\" value=\"" . htmlspecialchars($content) . "\" required>";
echo "            <div class=\"invalid-feedback\">" . _('Provide content') . "</div>";
echo "        </td>\n";
echo "        <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"prio\" value=\"" . htmlspecialchars($prio) . "\"></td>\n";
echo "        <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"ttl\" value=\"" . htmlspecialchars($ttl) . "\"</td>\n";
echo "       </tr>\n";
echo "      </table>\n";
echo "      <br>\n";
echo "      <input class=\"btn btn-primary btn-sm\" type=\"submit\" name=\"commit\" value=\"" . _('Add record') . "\">\n";
if (isset($rev)) {
    echo "      $rev";
}
echo "     </form>\n";

include_once('inc/footer.inc.php');
