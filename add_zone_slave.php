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
 * Script that handles requests to add new slave zone
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

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$dns_third_level_check = $app->config('dns_third_level_check');

$owner = "-1";
if ((isset($_POST['owner'])) && (Validation::is_number($_POST['owner']))) {
    $owner = $_POST['owner'];
}

$zone = "";
if (isset($_POST['domain'])) {
    $zone = idn_to_ascii(trim($_POST['domain']), IDNA_NONTRANSITIONAL_TO_ASCII);
}

$master = "";
if (isset($_POST['slave_master'])) {
    $master = $_POST['slave_master'];
}

$type = "SLAVE";

$zone_slave_add = do_hook('verify_permission', 'zone_slave_add');

if (!$zone_slave_add) {
    error(ERR_PERM_ADD_ZONE_SLAVE);
    include_once('inc/footer.inc.php');
    exit;
}

if (isset($_POST['submit'])) {
    if (!Dns::is_valid_hostname_fqdn($zone, 0)) {
        error(ERR_DNS_HOSTNAME);
    } elseif ($dns_third_level_check && DnsRecord::get_domain_level($zone) > 2 && DnsRecord::domain_exists(DnsRecord::get_second_level_domain($zone))) {
        error(ERR_DOMAIN_EXISTS);
    } elseif (DnsRecord::domain_exists($zone) || DnsRecord::record_name_exists($zone)) {
        error(ERR_DOMAIN_EXISTS);
    } elseif (!Dns::are_multiple_valid_ips($master)) {
        error(ERR_DNS_IP);
    } else {
        if (DnsRecord::add_domain($zone, $owner, $type, $master, 'none')) {
            $zone_id = DnsRecord::get_zone_id_from_name($zone);
            $idn_zone_name = idn_to_utf8($zone, IDNA_NONTRANSITIONAL_TO_ASCII);
            success("<a href=\"edit.php?id=" . $zone_id . "\">" . SUC_ZONE_ADD . '</a>');
            Logger::log_info(sprintf('client_ip:%s user:%s operation:add_zone zone:%s zone_type:SLAVE zone_master:%s',
                $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                $zone, $master), $zone_id);
            unset($zone, $owner, $type, $master);
        }
    }
}

$app->render('add_zone_slave.html', [
    'users' => do_hook('show_users'),
    'session_user_id' => $_SESSION['userid'],
    'perm_view_others' => do_hook('verify_permission', 'user_view_others'),
]);

include_once('inc/footer.inc.php');
