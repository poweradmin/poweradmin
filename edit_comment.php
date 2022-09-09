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

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

if (do_hook('verify_permission', 'zone_content_view_others')) {
    $perm_view = "all";
} elseif (do_hook('verify_permission', 'zone_content_view_own')) {
    $perm_view = "own";
} else {
    $perm_view = "none";
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

if (do_hook('verify_permission', 'zone_meta_edit_others')) {
    $perm_meta_edit = "all";
} elseif (do_hook('verify_permission', 'zone_meta_edit_own')) {
    $perm_meta_edit = "own";
} else {
    $perm_meta_edit = "none";
}

$zid = $_GET['domain'];

$user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zid);
$zone_type = DnsRecord::get_domain_type($zid);
$zone_name = DnsRecord::get_domain_name_by_id($zid);

if (isset($_POST["commit"])) {
    if ($zone_type == "SLAVE" || $perm_content_edit == "none" || ($perm_content_edit == "own" || $perm_content_edit == "own_as_client") && $user_is_zone_owner == "0") {
        error(ERR_PERM_EDIT_COMMENT);
    } else {
        DnsRecord::edit_zone_comment($_GET['domain'], $_POST['comment']);
        success(SUC_COMMENT_UPD);
    }
}

if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
    error(ERR_PERM_VIEW_COMMENT);
    include_once("inc/footer.inc.php");
    exit;
}

$app = AppFactory::create();
$app->render('edit_comment.html', [
    'zid' => $zid,
    'comment' => DnsRecord::get_zone_comment($zid),
    'zone_type' => $zone_type,
    'perm_content_edit' => $perm_content_edit,
    'user_is_zone_owner' => $user_is_zone_owner,
]);

include_once('inc/footer.inc.php');
