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
 * Script that handles deletion of zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\Validation;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

if (!do_hook('verify_permission' , 'user_edit_templ_perm' )) {
    error(ERR_PERM_DEL_PERM_TEMPL);
    include_once('inc/footer.inc.php');
    die();
}

if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
    error(ERR_INV_INPUT);
    include_once('inc/footer.inc.php');
    die();
}

$perm_templ_id = $_GET['id'];

if (isset($_GET['confirm']) && Validation::is_number($_GET['confirm']) && $_GET["confirm"] == '1') {
    if (do_hook('delete_perm_templ', $perm_templ_id)) {
        success(SUC_PERM_TEMPL_DEL);
    }
    include_once('inc/footer.inc.php');
    die();
}

$templ_details = do_hook('get_permission_template_details' , $perm_templ_id );

$app = AppFactory::create();
$app->render('delete_perm_templ.html', [
    'perm_templ_id' => $perm_templ_id,
    'templ_name' => $templ_details['name'],
]);

include_once("inc/footer.inc.php");

