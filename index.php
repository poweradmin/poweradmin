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
 * Script which displays available actions
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\LegacyUsers;
use Poweradmin\Permission;

require_once __DIR__ . '/vendor/autoload.php';

class IndexController extends BaseController
{
    public function run(): void
    {
        $this->showIndex();
    }

    private function showIndex(): void
    {
        $template = sprintf("index_%s.html", $this->config('iface_index'));

        $userlogin = $_SESSION["userlogin"] ?? '';

        $permissions = Permission::getPermissions($this->db, [
            'perm_search',
            'perm_view_zone_own',
            'perm_view_zone_other',
            'perm_supermaster_view',
            'perm_zone_master_add',
            'perm_zone_slave_add',
            'perm_supermaster_add',
            'perm_is_godlike',
            'perm_templ_perm_edit',
        ]);

        $this->render($template, [
            'user_name' => empty($_SESSION["name"]) ? $userlogin : $_SESSION["name"],
            'auth_used' => $_SESSION["auth_used"] ?? '',
            'permissions' => $permissions,
            'dblog_use' => $this->config('dblog_use')
        ]);
    }
}

$controller = new IndexController();
$controller->run();
