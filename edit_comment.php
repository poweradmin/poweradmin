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
 */

/**
 * Script that handles editing of zone comments
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

if (verify_permission('zone_content_view_others')) {
    $perm_view = "all";
} elseif (verify_permission('zone_content_view_own')) {
    $perm_view = "own";
} else {
    $perm_view = "none";
}

if (verify_permission('zone_content_edit_others')) {
    $perm_content_edit = "all";
} elseif (verify_permission('zone_content_edit_own')) {
    $perm_content_edit = "own";
} else {
    $perm_content_edit = "none";
}

if (verify_permission('zone_meta_edit_others')) {
    $perm_meta_edit = "all";
} elseif (verify_permission('zone_meta_edit_own')) {
    $perm_meta_edit = "own";
} else {
    $perm_meta_edit = "none";
}

$zid = $_GET['domain'];

$user_is_zone_owner = verify_user_is_owner_zoneid($zid);
$zone_type = get_domain_type($zid);
$zone_name = get_zone_name_from_id($zid);

if (isset($_POST["commit"])) {
    if ($zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0") {
        error(ERR_PERM_EDIT_COMMENT);
    } else {
        edit_zone_comment($_GET['domain'], $_POST['comment']);
        success(SUC_COMMENT_UPD);
    }
}

echo "    <h2>" . _('Edit comment in zone') . " " . $zone_name . "</h2>\n";

if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
    error(ERR_PERM_VIEW_COMMENT);
} else {
    $comment = get_zone_comment($zid);
    echo "     <form method=\"post\" action=\"edit_comment.php?domain=" . $zid . "\">\n";
    echo "      <table>\n";
    echo "      <tr>\n";
    echo "       <td colspan=\"6\">&nbsp;</td>\n";
    echo "      </tr>\n";
    echo "      <tr>\n";
    echo "       <td>&nbsp;</td><td colspan=\"5\">Comments:</td>\n";
    echo "      </tr>\n";

    if ($zone_type == "SLAVE" || $perm_content_edit == "none" || $perm_content_edit == "own" && $user_is_zone_owner == "0") {
        echo "    <tr>\n";
        echo "     <td class=\"n\">\n";
        echo "      &nbsp;\n";
        echo "     </td>\n";
        echo "     <td colspan=\"4\"><textarea rows=\"15\" name=\"comment\" disabled>" . $comment . "</textarea></td>\n";
        echo "     <td>&nbsp;</td>\n";
        echo "    </tr>\n";
    } else {
        echo "    <tr>\n";
        echo "     <td class=\"n\">\n";
        echo "      &nbsp;\n";
        echo "     </td>\n";
        echo "     <td colspan=\"4\"><textarea rows=\"15\" name=\"comment\">" . $comment . "</textarea></td>\n";
        echo "     <td>&nbsp;</td>\n";
        echo "    </tr>\n";
    }
    echo "      </table>\n";
    echo "      <p>\n";
    echo "       <input type=\"submit\" name=\"commit\" value=\"" . _('Commit changes') . "\" class=\"button\">&nbsp;&nbsp;\n";
    echo "       <input type=\"reset\" name=\"reset\" value=\"" . _('Reset changes') . "\" class=\"button\">&nbsp;&nbsp;\n";
    echo "      </p>\n";
    echo "     </form>\n";
}


include_once("inc/footer.inc.php");
