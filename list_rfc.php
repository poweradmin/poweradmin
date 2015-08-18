<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2015  Poweradmin Development Team
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
 * Script that displays rfcs of resource and zone changes.
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2015 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
require_once("inc/RfcPermissions.class.php");
require_once("inc/RfcResolver.class.php");
require_once("inc/RFCRender.class.php");
include_once("inc/header.inc.php");
require_once("inc/util/PoweradminUtil.class.php");

if (isset($_GET['success'])) {
    success(SUC_RFC_DELETED);
}

echo "<h2>" . _('Manage RFCs') . "</h2>";

if(!RfcPermissions::can_view_rfcs()) {
    echo "<p>" . _('You do not have the permission to see the RFCs.') . "</p>";
    include_once("inc/footer.inc.php");
    exit;
}
global $db;

$r = new RfcResolver($db);
$rfcs = $r->build_rfcs_from_db();
$renderer = new RFCRender($rfcs);
echo $renderer->get_html();


include_once("inc/footer.inc.php");
