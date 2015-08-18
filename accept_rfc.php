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
 * Script that accepts a given RFC and writes it to the database
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2015 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
require_once("inc/RfcResolver.class.php");
require_once("inc/RfcAcceptor.class.php");
global $db;

$rfc_id = null;
if (isset($_GET['id']) && v_num($_GET['id'])) {
    $rfc_id = $_GET['id'];
} else {
    http_response_code(400);
    exit;
}
$rfc_resolver = new RfcResolver($db);
$all_rfcs = $rfc_resolver->build_rfcs_from_db();

$this_rfc = $all_rfcs[$rfc_id];
$acceptor = new RfcAcceptor($db);
$acceptor->accept($this_rfc);

http_response_code(200);
header('Location: list_rfc.php?flash=success_accept');
