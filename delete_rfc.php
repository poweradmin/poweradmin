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
 * Script that handles deleting RFCs.
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2015 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");
require_once("inc/RfcPermissions.class.php");
require_once("inc/RfcManager.class.php");

$rfc_id = null;
if (isset($_POST['id']) && v_num($_POST['id'])) {
    $rfc_id = $_POST['id'];
} else {
    header("Location: list_rfc.php");
    exit;
}

global $db;
$manager = new RfcManager($db);
$manager->delete_rfc($rfc_id);

header("Location: list_rfc.php?success");
