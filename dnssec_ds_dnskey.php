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
 *
 */

/**
 * Script that handles editing of zone records
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

if ($zone_id == "-1") {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;
}

/*
  Check permissions
 */
$user_is_zone_owner = verify_user_is_owner_zoneid($zone_id);
if ($perm_meta_edit == "all" || ( $perm_meta_edit == "own" && $user_is_zone_owner == "1")) {
    $meta_edit = "1";
} else {
    $meta_edit = "0";
}

(verify_permission('user_view_others')) ? $perm_view_others = "1" : $perm_view_others = "0";

if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
    error(ERR_PERM_VIEW_ZONE);
    include_once("inc/footer.inc.php");
    exit();
}

if (zone_id_exists($zone_id) == "0") {
    error(ERR_ZONE_NOT_EXIST);
    include_once("inc/footer.inc.php");
    exit();
}

$domain_type = get_domain_type($zone_id);
$domain_name = get_zone_name_from_id($zone_id);
$record_count = count_zone_records($zone_id);
$zone_templates = get_list_zone_templ($_SESSION['userid']);
$zone_template_id = get_zone_template($zone_id);

echo "   <h2>" . _('DNSSEC public records for zone') . " \"" . get_zone_name_from_id($zone_id) . "\"</h2>\n";

echo "   <h3>" . _('DNSKEY') . "</h3>\n";
$dnskey_record = dnssec_dnskey_record($domain_name);
echo $dnskey_record."<br>\n";

echo "<br>";

echo "   <h3>" . _('DS record') . "</h3>\n";
$ds_records = dnssec_ds_records($domain_name);
foreach ($ds_records as $record) {
    echo $record."<br>\n";
}

echo "<br>";

include_once("inc/footer.inc.php");
