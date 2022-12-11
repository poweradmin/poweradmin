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
 * Script that handles editing of zone comments
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\DnsRecord;
use Poweradmin\Permission;
use Poweradmin\Validation;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$iface_zone_comments = $app->config('iface_zone_comments');

if (!$iface_zone_comments) {
    error(ERR_PERM_EDIT_COMMENT);
    include_once('inc/footer.inc.php');
    exit;
}

$perm_view = Permission::getViewPermission();
$perm_edit = Permission::getEditPermission();

if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
    error(ERR_INV_INPUT);
    include_once('inc/footer.inc.php');
    exit;
}
$zone_id = htmlspecialchars($_GET['id']);

$user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);
$zone_type = DnsRecord::get_domain_type($zone_id);
$zone_name = DnsRecord::get_domain_name_by_id($zone_id);

$perm_edit_comment = $zone_type == "SLAVE" || $perm_edit == "none" || ($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0";

if (isset($_POST["commit"])) {
    if ($perm_edit_comment) {
        error(ERR_PERM_EDIT_COMMENT);
    } else {
        DnsRecord::edit_zone_comment($zone_id, $_POST['comment']);
        success(SUC_COMMENT_UPD);
    }
}

if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
    error(ERR_PERM_VIEW_COMMENT);
    include_once("inc/footer.inc.php");
    exit;
}

$app->render('edit_comment.html', [
    'zone_id' => $zone_id,
    'comment' => DnsRecord::get_zone_comment($zone_id),
    'disabled' => $perm_edit_comment
]);

include_once('inc/footer.inc.php');
