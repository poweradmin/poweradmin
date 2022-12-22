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
 * Script that handles requests to add new records to zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\RecordType;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

class AddZoneTemplRecordController extends BaseController {

    public function run(): void
    {
        $v = new Valitron\Validator($_GET);
        $v->rule('required', ['id']);
        if (!$v->validate()) {
            $this->showErrors($v->errors());
        }

        $zone_templ_id = htmlspecialchars($_GET['id']);
        $owner = ZoneTemplate::get_zone_templ_is_owner($zone_templ_id, $_SESSION['userid']);

        $this->checkCondition(!do_hook('verify_permission' , 'zone_master_add' ) || !$owner, ERR_PERM_ADD_RECORD);

        if ($this->isPost()) {
            $v = new Valitron\Validator($_POST);
            $v->rules(['required' => [
                'name', 'type', 'content', 'prio', 'ttl'
            ]]);
            if ($v->validate()) {
                $this->addZoneTemplRecord();
            } else {
                $this->showErrors($v->errors());
            }
        } else {
            $this->showAddZoneTemplRecord();
        }
    }

    private function addZoneTemplRecord()
    {
        $zone_templ_id = htmlspecialchars($_GET['id']);
        $name = $_POST['name'] ?? "[ZONE]";
        $type = $_POST['type'] ?? "";
        $content = $_POST['content'] ?? "";
        $prio = $_POST['prio'] ?? 0;
        $dns_ttl = $this->config('dns_ttl');
        $ttl = $_POST['ttl'] ?? $dns_ttl;

        if (ZoneTemplate::add_zone_templ_record($zone_templ_id, $name, $type, $content, $ttl, $prio)) {
            $this->setMessage('edit_zone_templ', 'success', 'The record was successfully added.');
            $this->redirect('edit_zone_templ.php', ['id' => $zone_templ_id]);
        } else {
            $this->showAddZoneTemplRecord();
        }
    }

    private function showAddZoneTemplRecord()
    {
        $zone_templ_id = htmlspecialchars($_GET['id']);
        $templ_details = ZoneTemplate::get_zone_templ_details($zone_templ_id);
        $name = $_POST['name'] ?? "[ZONE]";
        $type = $_POST['type'] ?? "";
        $content = $_POST['content'] ?? "";
        $prio = $_POST['prio'] ?? 0;
        $dns_ttl = $this->config('dns_ttl');
        $ttl = $_POST['ttl'] ?? $dns_ttl;

        $this->render('add_zone_templ_record.html', [
            'templ_name' => $templ_details['name'],
            'zone_templ_id' => $zone_templ_id,
            'name' => htmlspecialchars($name),
            'type' => htmlspecialchars($type),
            'record_types' => RecordType::getTypes(),
            'content' => htmlspecialchars($content),
            'prio' => htmlspecialchars($prio),
            'ttl' => htmlspecialchars($ttl),
        ]);
    }
}

$controller = new AddZoneTemplRecordController();
$controller->run();
