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
        $userlogin = $_SESSION["userlogin"] ?? '';

        $permissions = Permission::getPermissions($this->db, [
            'search',
            'zone_content_view_own',
            'zone_content_view_others',
            'zone_content_edit_own',
            'zone_content_edit_others',
            'supermaster_view',
            'zone_master_add',
            'zone_slave_add',
            'supermaster_add',
            'user_is_ueberuser',
            'templ_perm_edit',
            'user_view_others',
            'user_edit_own',
            'user_edit_others',
            'user_add_new',
        ]);

        // Check PowerDNS server status if API is enabled and user is admin
        $pdnsServerStatus = null;
        $pdnsApiEnabled = !empty($this->config->get('pdns_api', 'url', '')) && !empty($this->config->get('pdns_api', 'key', ''));
        $showPdnsStatus = $this->config->get('interface', 'show_pdns_status', false);

        if ($pdnsApiEnabled && $showPdnsStatus && $permissions['user_is_ueberuser']) {
            $statusService = new \Poweradmin\Application\Service\PowerdnsStatusService();
            $serverStatus = $statusService->getServerStatus();
            $pdnsServerStatus = [
                'running' => $serverStatus['running'] ?? false,
                'version' => $serverStatus['version'] ?? 'unknown'
            ];
        }

        $this->render("index.html", [
            'user_name' => empty($_SESSION["name"]) ? $userlogin : $_SESSION["name"],
            'auth_used' => $_SESSION["auth_used"] ?? '',
            'permissions' => $permissions,
            'dblog_use' => $this->config->get('logging', 'database_enabled', false),
            'migrations_show' => $this->config->get('interface', 'show_migrations', false),
            'iface_add_reverse_record' => $this->config->get('interface', 'add_reverse_record', true),
            'whois_enabled' => $this->config->get('whois', 'enabled', false),
            'rdap_enabled' => $this->config->get('rdap', 'enabled', false),
            'api_enabled' => $this->config->get('api', 'enabled', false),
            'whois_restrict_to_admin' => $this->config->get('whois', 'restrict_to_admin', true),
            'rdap_restrict_to_admin' => $this->config->get('rdap', 'restrict_to_admin', true),
            'pdns_api_enabled' => $pdnsApiEnabled,
            'show_pdns_status' => $showPdnsStatus,
            'pdns_server_status' => $pdnsServerStatus,
        ]);
    }
}
