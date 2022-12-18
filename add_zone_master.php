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
 * Script that handles requests to add new master zones
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\Dns;
use Poweradmin\DnsRecord;
use Poweradmin\Dnssec;
use Poweradmin\Logger;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;
use Poweradmin\ZoneType;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$pdnssec_use = $app->config('pdnssec_use');
$dns_third_level_check = $app->config('dns_third_level_check');

$owner = "-1";
if ((isset($_POST['owner'])) && (Validation::is_number($_POST['owner']))) {
    $owner = $_POST['owner'];
}

$dom_type = "NATIVE";
if (isset($_POST["dom_type"]) && (in_array($_POST['dom_type'], ZoneType::getTypes()))) {
    $dom_type = $_POST["dom_type"];
}

$zone = "";
if (isset($_POST['domain'])) {
    $zone = idn_to_ascii(trim($_POST['domain']), IDNA_NONTRANSITIONAL_TO_ASCII);
}

$zone_template = $_POST['zone_template'] ?? "none";
$enable_dnssec = isset($_POST['dnssec']) && $_POST['dnssec'] == '1';

$zone_master_add = do_hook('verify_permission', 'zone_master_add');
$perm_view_others = do_hook('verify_permission', 'user_view_others');

if (isset($_POST['submit']) && $zone_master_add) {
    if (!Dns::is_valid_hostname_fqdn($zone, 0)) {
        error($zone . ' failed - ' . ERR_DNS_HOSTNAME);
    } elseif ($dns_third_level_check && DnsRecord::get_domain_level($zone) > 2 && DnsRecord::domain_exists(DnsRecord::get_second_level_domain($zone))) {
        $idn_zone_name = idn_to_utf8($zone, IDNA_NONTRANSITIONAL_TO_ASCII);
        error($idn_zone_name . ' failed - ' . ERR_DOMAIN_EXISTS);
    } elseif (DnsRecord::domain_exists($zone) || DnsRecord::record_name_exists($zone)) {
        $idn_zone_name = idn_to_utf8($zone, IDNA_NONTRANSITIONAL_TO_ASCII);
        error($idn_zone_name . ' failed - ' . ERR_DOMAIN_EXISTS);
        // TODO: repopulate domain name(s) to the form if there was an error occured
    } elseif (DnsRecord::add_domain($zone, $owner, $dom_type, '', $zone_template)) {
        $zone_id = DnsRecord::get_zone_id_from_name($zone);
        $idn_zone_name = idn_to_utf8($zone, IDNA_NONTRANSITIONAL_TO_ASCII);
        success("<a href=\"edit.php?id=" . $zone_id . "\">" . $idn_zone_name . " - " . SUC_ZONE_ADD . '</a>');
        Logger::log_info(sprintf('client_ip:%s user:%s operation:add_zone zone:%s zone_type:%s zone_template:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
            $zone, $dom_type, $zone_template), $zone_id);

        if ($pdnssec_use) {
            if ($enable_dnssec) {
                Dnssec::dnssec_secure_zone($zone);
            }

            Dnssec::dnssec_rectify_zone($zone_id);
        }

        unset($zone, $owner, $dom_type, $zone_template);
    }
}

if (!$zone_master_add) {
    error(ERR_PERM_ADD_ZONE_MASTER);
    include_once('inc/footer.inc.php');
    exit;
}

$app->render('add_zone_master.html', [
    'perm_view_others' => $perm_view_others,
    'session_user_id' => $_SESSION['userid'],
    'available_zone_types' => array("MASTER", "NATIVE"),
    'users' => do_hook('show_users'),
    'zone_templates' => ZoneTemplate::get_list_zone_templ($_SESSION['userid']),
    'iface_zone_type_default' => $app->config('iface_zone_type_default'),
]);

include_once('inc/footer.inc.php');
