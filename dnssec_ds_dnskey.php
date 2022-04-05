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
 *
 */

/**
 * Script that handles editing of zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\DnsRecord;
use Poweradmin\Dnssec;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$pdnssec_use = $app->config('pdnssec_use');

$zone_id = "-1";
if (isset($_GET['id']) && Validation::is_number($_GET['id'])) {
    $zone_id = htmlspecialchars($_GET['id']);
}

if ($zone_id == "-1") {
    error(ERR_INV_INPUT);
    include_once('inc/footer.inc.php');
    exit;
}

if (do_hook('verify_permission', 'zone_meta_edit_others')) {
    $perm_meta_edit = "all";
} elseif (do_hook('verify_permission', 'zone_meta_edit_own')) {
    $perm_meta_edit = "own";
} else {
    $perm_meta_edit = "none";
}

$user_is_zone_owner = do_hook('verify_user_is_owner_zoneid' , $zone_id );
if ($perm_meta_edit == "all" || ( $perm_meta_edit == "own" && $user_is_zone_owner == "1")) {
    $meta_edit = "1";
} else {
    $meta_edit = "0";
}

(do_hook('verify_permission' , 'user_view_others' )) ? $perm_view_others = "1" : $perm_view_others = "0";

if (do_hook('verify_permission', 'zone_content_view_others')) {
    $perm_view = "all";
} elseif (do_hook('verify_permission', 'zone_content_view_own')) {
    $perm_view = "own";
} else {
    $perm_view = "none";
}

if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
    error(ERR_PERM_VIEW_ZONE);
    include_once("inc/footer.inc.php");
    exit();
}

if (DnsRecord::zone_id_exists($zone_id) == "0") {
    error(ERR_ZONE_NOT_EXIST);
    include_once("inc/footer.inc.php");
    exit();
}

$domain_type = DnsRecord::get_domain_type($zone_id);
$domain_name = DnsRecord::get_domain_name_by_id($zone_id);
$record_count = DnsRecord::count_zone_records($zone_id);
$zone_templates = ZoneTemplate::get_list_zone_templ($_SESSION['userid']);
$zone_template_id = DnsRecord::get_zone_template($zone_id);

echo "   <h2>" . _('DNSSEC public records for zone') . " \"" . DnsRecord::get_domain_name_by_id($zone_id) . "\"</h2>\n";

echo "   <h3>" . _('DNSKEY') . "</h3>\n";
$dnskey_records = Dnssec::dnssec_get_dnskey_record($domain_name);
foreach ($dnskey_records as $record) {
    echo $record."<br/>";
}
echo "<br>";

echo "   <h3>" . _('DS record') . "</h3>\n";
$ds_records = Dnssec::dnssec_get_ds_records($domain_name);
foreach ($ds_records as $record) {
    echo $record."<br>\n";
}

echo "<br>";
echo "<br/><a href='dnssec.php?id=" . $zone_id . "'>Back to DNSSEC " . $domain_name . "</a>";

include_once("inc/footer.inc.php");
