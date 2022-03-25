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
 * Script that handles zone deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\DnsRecord;
use Poweradmin\Dnssec;
use Poweradmin\Syslog;
use Poweradmin\Validation;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();

global $pdnssec_use;

if (do_hook('verify_permission', 'zone_content_edit_others')) {
    $perm_edit = "all";
} elseif (do_hook('verify_permission', 'zone_content_edit_own')) {
    $perm_edit = "own";
} else {
    $perm_edit = "none";
}

if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
    error(ERR_INV_INPUT);
    include_once('inc/footer.inc.php');
    exit;
}
$zone_id = $_GET['id'];

$confirm = "-1";
if (isset($_GET['confirm']) && Validation::is_number($_GET['confirm'])) {
    $confirm = $_GET['confirm'];
}

$zone_info = DnsRecord::get_zone_info_from_id($zone_id);
if (!$zone_info) {
    header("Location: list_zones.php");
    exit;
}

$zone_owners = do_hook('get_fullnames_owners_from_domainid', $zone_id);
$user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);

if ($confirm == '1') {
    if ($pdnssec_use && $zone_info['type'] == 'MASTER') {
        $zone_name = DnsRecord::get_domain_name_by_id($zone_id);
        Dnssec::dnssec_unsecure_zone($zone_name);
    }

    if (DnsRecord::delete_domain($zone_id)) {
        success(SUC_ZONE_DEL);
        Syslog::log_info(sprintf('client_ip:%s user:%s operation:delete_zone zone:%s zone_type:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
            $zone_info['name'], $zone_info['type']));
    }
    include_once('inc/footer.inc.php');
    exit;
}

if ($perm_edit != "all" && ($perm_edit != "own" || $user_is_zone_owner != "1")) {
    error(ERR_PERM_DEL_ZONE);
    include_once('inc/footer.inc.php');
    exit;
}

$slave_master = '';
$slave_master_exists = false;
if ($zone_info['type'] == "SLAVE") {
    $slave_master = DnsRecord::get_domain_slave_master($zone_id);
    if (DnsRecord::supermaster_exists($slave_master)) {
        $slave_master_exists = true;
    }
}

$app->render('delete_domain.html', [
    'zone_id' => $zone_id,
    'zone_info' => $zone_info,
    'zone_owners' => $zone_owners,
    'slave_master_exists' => $slave_master_exists,
]);

include_once("inc/footer.inc.php");
