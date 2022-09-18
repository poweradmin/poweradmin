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
 * Script that handles record deletions from zones
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
use Poweradmin\Logger;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$pdnssec_use = $app->config('pdnssec_use');

if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
    error(ERR_INV_INPUT);
    include_once('inc/footer.inc.php');
    exit;
}
$record_id = htmlspecialchars($_GET['id']);

$zid = DnsRecord::get_zone_id_from_record_id($record_id);
if ($zid == NULL) {
    header("Location: list_zones.php");
    exit;
}

if (isset($_GET['confirm']) && Validation::is_number($_GET['confirm']) && $_GET['confirm'] == 1) {
    $record_info = DnsRecord::get_record_from_id($record_id);
    if (DnsRecord::delete_record($record_id)) {
        success("<a href=\"edit.php?id=" . $zid . "\">" . SUC_RECORD_DEL . "</a>");
        if (isset($record_info['prio'])) {
            Logger::log_info(sprintf('client_ip:%s user:%s operation:delete_record record_type:%s record:%s content:%s ttl:%s priority:%s',
                $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                $record_info['type'], $record_info['name'], $record_info['content'], $record_info['ttl'], $record_info['prio'] ), $zid);
        } else {
            Logger::log_info(sprintf('client_ip:%s user:%s operation:delete_record record_type:%s record:%s content:%s ttl:%s',
                $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                $record_info['type'], $record_info['name'], $record_info['content'], $record_info['ttl'] ), $zid);

        }

        DnsRecord::delete_record_zone_templ($record_id);
        DnsRecord::update_soa_serial($zid);

        // do also rectify-zone
        if ($pdnssec_use && Dnssec::dnssec_rectify_zone($zid)) {
            success(SUC_EXEC_PDNSSEC_RECTIFY_ZONE);
        }
    }

    include_once('inc/footer.inc.php');
    exit;
}

if (do_hook('verify_permission', 'zone_content_edit_others')) {
    $perm_content_edit = "all";
} elseif (do_hook('verify_permission', 'zone_content_edit_own')) {
    $perm_content_edit = "own";
} elseif (do_hook('verify_permission', 'zone_content_edit_own_as_client')) {
    $perm_content_edit = "own_as_client";
} else {
    $perm_content_edit = "none";
}

$zone_info = DnsRecord::get_zone_info_from_id($zid);
$zone_id = DnsRecord::recid_to_domid($record_id);
$user_is_zone_owner = do_hook('verify_user_is_owner_zoneid' , $zone_id );
if ($zone_info['type'] == "SLAVE" || $perm_content_edit == "none" || ($perm_content_edit == "own" || $perm_content_edit == "own_as_client") && $user_is_zone_owner == "0") {
    error(ERR_PERM_EDIT_RECORD);
    include_once('inc/footer.inc.php');
    exit;
}

$app->render('delete_record.html', [
    'record_id' => $record_id,
    'zid' => $zid,
    'zone_name' => DnsRecord::get_domain_name_by_id($zone_id),
    'record_info' => DnsRecord::get_record_from_id($record_id),
]);

include_once('inc/footer.inc.php');
