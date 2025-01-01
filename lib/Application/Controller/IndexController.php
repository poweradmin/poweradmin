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
 * Script which displays available actions
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;

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
            'search',
            'zone_content_view_own',
            'zone_content_view_others',
            'supermaster_view',
            'zone_master_add',
            'zone_slave_add',
            'supermaster_add',
            'user_is_ueberuser',
            'templ_perm_edit',
        ]);

        $this->render($template, [
            'user_name' => empty($_SESSION["name"]) ? $userlogin : $_SESSION["name"],
            'auth_used' => $_SESSION["auth_used"] ?? '',
            'permissions' => $permissions,
            'dblog_use' => $this->config('dblog_use'),
            'migrations_show' => $this->config('iface_migrations_show'),
        ]);
    }
}
