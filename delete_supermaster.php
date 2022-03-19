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
 * Script that handles deletion of supermasters
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\Dns;
use Poweradmin\DnsRecord;
use Poweradmin\Validation;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

if (!isset($_GET['master_ip']) || !Dns::is_valid_ipv4($_GET['master_ip']) && !Dns::is_valid_ipv6($_GET['master_ip'])) {
    error(ERR_INV_INPUT);
    include_once('inc/footer.inc.php');
    die();
}
$master_ip = $_GET['master_ip'];

if (!isset($_GET['ns_name']) || !Dns::is_valid_hostname_fqdn($_GET['ns_name'], 0)) {
    error(ERR_INV_INPUT);
    include_once('inc/footer.inc.php');
    die();
}
$ns_name = $_GET['ns_name'];

if (isset($_GET['confirm']) && !Validation::is_number($_GET['confirm'])) {
    error(ERR_INV_INPUT);
    include_once('inc/footer.inc.php');
    die();
}

$perm_sm_edit = do_hook('verify_permission', 'supermaster_edit');
if (!$perm_sm_edit) {
    error(ERR_PERM_DEL_SM);
    include_once('inc/footer.inc.php');
    die();
}

if (isset($_GET['confirm']) && $_GET['confirm'] == '1') {
    if (!DnsRecord::supermaster_ip_name_exists($master_ip, $ns_name)) {
        header("Location: list_supermasters.php");
        exit;
    }

    if (DnsRecord::delete_supermaster($master_ip, $ns_name)) {
        success(SUC_SM_DEL);
    }
    include_once('inc/footer.inc.php');
    die();
}

$info = DnsRecord::get_supermaster_info_from_ip($master_ip);

$app = AppFactory::create();
$app->render('delete_supermaster.html', [
    'master_ip' => $master_ip,
    'info' => $info
]);

include_once("inc/footer.inc.php");
