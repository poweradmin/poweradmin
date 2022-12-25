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
 * Script that handles zone template deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
require_once 'inc/messages.inc.php';

class DeleteZoneTemplController extends BaseController
{
    public function run(): void
    {
        $zone_templ_id = htmlspecialchars($_GET['id']);
        $owner = ZoneTemplate::get_zone_templ_is_owner($zone_templ_id, $_SESSION['userid']);
        $this->checkCondition(!do_hook('verify_permission', 'zone_master_add') || !$owner, _("You do not have the permission to delete zone templates."));

        if (isset($_GET['confirm'])) {
            $this->deleteZoneTempl();
        } else {
            $this->showDeleteZoneTempl();
        }
    }

    private function deleteZoneTempl()
    {
        $v = new Valitron\Validator($_GET);
        $v->rules([
            'required' => ['id'],
            'integer' => ['id'],
        ]);

        if ($v->validate()) {
            $zone_templ_id = htmlspecialchars($_GET['id']);
            ZoneTemplate::delete_zone_templ($zone_templ_id);

            $this->setMessage('list_zone_templ', 'success', _('Zone template has been deleted successfully.'));
            $this->redirect('list_zone_templ.php');
        } else {
            $this->showFirstError($v->errors());
        }
    }

    private function showDeleteZoneTempl()
    {
        $zone_templ_id = htmlspecialchars($_GET['id']);
        $templ_details = ZoneTemplate::get_zone_templ_details($zone_templ_id);

        $this->render('delete_zone_templ.html', [
            'templ_name' => $templ_details['name'],
            'zone_templ_id' => $zone_templ_id,
        ]);
    }
}

$controller = new DeleteZoneTemplController();
$controller->run();
