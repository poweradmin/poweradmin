<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
 * Script that displays list of zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;

class ListZoneTemplController extends BaseController
{

    public function run(): void
    {
        $this->checkPermission('zone_master_add', _("You do not have the permission to edit zone templates."));

        $this->showListZoneTempl();
    }

    private function showListZoneTempl(): void
    {
        $perm_zone_master_add = UserManager::verify_permission($this->db, 'zone_master_add');

        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());
        $zone_templates->get_list_zone_templ($_SESSION['userid']);

        $this->render('list_zone_templ.html', [
            'perm_zone_master_add' => $perm_zone_master_add,
            'user_name' => UserManager::get_fullname_from_userid($this->db, $_SESSION['userid']) ?: $_SESSION['userlogin'],
            'zone_templates' => $zone_templates->get_list_zone_templ($_SESSION['userid']),
            'perm_is_godlike' => UserManager::verify_permission($this->db, 'user_is_ueberuser'),
        ]);
    }
}
