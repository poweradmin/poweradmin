<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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
 * Script that handles records editing in zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\RecordType;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
require_once 'inc/messages.inc.php';

class EditZoneTemplRecordController extends BaseController {

    public function run(): void
    {
        $v = new Valitron\Validator($_GET);
        $v->rules([
            'required' => ['id', 'zone_templ_id'],
            'integer' => ['id', 'zone_templ_id'],
        ]);
        if (!$v->validate()) {
            $this->showFirstError($v->errors());
        }

        $record_id = htmlspecialchars($_GET['id']);
        $zone_templ_id = htmlspecialchars($_GET['zone_templ_id']);

        $zone_master_add = do_hook('verify_permission', 'zone_master_add');
        $owner = ZoneTemplate::get_zone_templ_is_owner($zone_templ_id, $_SESSION['userid']);
        $this->checkCondition(!$zone_master_add || !$owner, _("You do not have the permission to view this record."));

        if ($this->isPost()) {
            $this->updateZoneTemplateRecord($zone_templ_id);
        }

        $this->showZoneTemplateRecordForm($record_id, $zone_templ_id);
    }

    public function showZoneTemplateRecordForm(string $record_id, string $zone_templ_id): void
    {
        $record = ZoneTemplate::get_zone_templ_record_from_id($record_id);

        $this->render('edit_zone_templ_record.html', [
            'record' => $record,
            'zone_templ_id' => $zone_templ_id,
            'record_id' => $record_id,
            'templ_details' => ZoneTemplate::get_zone_templ_details($zone_templ_id),
            'record_types' => RecordType::getTypes(),
        ]);
    }

    public function updateZoneTemplateRecord(string $zone_templ_id): void
    {
        $ret_val = ZoneTemplate::edit_zone_templ_record($_POST);
        if ($ret_val == "1") {
            $this->setMessage('edit_zone_templ', 'success', _('Zone template has been updated successfully.'));
            $this->redirect('edit_zone_templ.php', ['id' => $zone_templ_id]);
        } else {
            echo "     <div class=\"alert alert-danger\">" . $ret_val . "</div>\n";
        }
    }
}

$controller = new EditZoneTemplRecordController();
$controller->run();
