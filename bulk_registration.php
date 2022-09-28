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
 * Script that handles bulk zone registration
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\Dns;
use Poweradmin\DnsRecord;
use Poweradmin\Logger;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;
use Poweradmin\ZoneType;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();

$owner = "-1";
if ((isset($_POST['owner'])) && (Validation::is_number($_POST['owner']))) {
    $owner = $_POST['owner'];
}

$dom_type = "NATIVE";
if (isset($_POST["dom_type"]) && (in_array($_POST['dom_type'], ZoneType::getTypes()))) {
    $dom_type = $_POST["dom_type"];
}

$domains = array();
if (isset($_POST['domains'])) {
    $domains = explode("\r\n", $_POST['domains']);
    foreach ($domains as $key => $domain) {
        $domain = trim($domain);
        if ($domain == '') {
            unset($domains[$key]);
        } else {
            $domains[$key] = $domain;
        }
    }
}

$zone_template = $_POST['zone_template'] ?? "none";

$zone_master_add = do_hook('verify_permission', 'zone_master_add');
$perm_view_others = do_hook('verify_permission', 'user_view_others');

$error = false;

if (isset($_POST['submit']) && $zone_master_add) {
    foreach ($domains as $domain) {
        if (!Dns::is_valid_hostname_fqdn($domain, 0)) {
            error($domain . ' failed - ' . ERR_DNS_HOSTNAME);
        } elseif (DnsRecord::domain_exists($domain)) {
            error($domain . " failed - " . ERR_DOMAIN_EXISTS);
            $error = true;
        } elseif (DnsRecord::add_domain($domain, $owner, $dom_type, '', $zone_template)) {
            $zone_id = DnsRecord::get_zone_id_from_name($domain);
            Logger::log_info(sprintf('client_ip:%s user:%s operation:add_zone zone:%s zone_type:%s zone_template:%s',
                $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                $domain, $dom_type, $zone_template), $zone_id);
            success("<a href=\"edit.php?id=" . DnsRecord::get_zone_id_from_name($domain) . "\">" . $domain . " - " . SUC_ZONE_ADD . '</a>');
        }
    }

    if ($error === false) {
        unset($owner, $dom_type, $zone_template);
    }
}

if (!$zone_master_add) {
    error(ERR_PERM_ADD_ZONE_MASTER);
    include_once('inc/footer.inc.php');
    exit;
}

$app->render('bulk_registration.html', [
    'userid' => $_SESSION['userid'],
    'error' => $error,
    'domains' => $domains,
    'perm_view_others' => $perm_view_others,
    'iface_zone_type_default' => $app->config('iface_zone_type_default'),
    'available_zone_types' => array("MASTER", "NATIVE"),
    'users' => do_hook('show_users'),
    'zone_templates' => ZoneTemplate::get_list_zone_templ($_SESSION['userid']),
]);

include_once('inc/footer.inc.php');
