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

use Poweradmin\Application\Service\PowerdnsStatusService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Module\ModuleRegistry;

class IndexController extends BaseController
{
    private UserContextService $userContextService;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        // Check if user is logged in; if not, redirect to login page
        if (!$this->userContextService->isAuthenticated()) {
            $this->redirect('/login');
            return;
        }

        $this->setCurrentPage('index');
        $this->setPageTitle(_('Dashboard'));

        $this->showIndex();
    }

    private function showIndex(): void
    {
        $userlogin = $this->userContextService->getLoggedInUsername();
        $userId = $this->userContextService->getLoggedInUserId();

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
            'zone_templ_add',
            'zone_templ_edit',
            'user_view_others',
            'user_edit_own',
            'user_edit_others',
            'user_add_new',
            'api_manage_keys',
        ]);

        // Check PowerDNS server status if API is enabled and user is admin
        $pdnsServerStatus = null;
        $pdnsApiEnabled = !empty($this->config->get('pdns_api', 'url', '')) && !empty($this->config->get('pdns_api', 'key', ''));
        $showPdnsStatus = $this->config->get('interface', 'show_pdns_status', false);

        if ($pdnsApiEnabled && $showPdnsStatus && $permissions['user_is_ueberuser']) {
            $statusService = new PowerdnsStatusService();
            $serverStatus = $statusService->getServerStatus();
            $pdnsServerStatus = [
                'display' => $serverStatus['display_name'] ?? 'PowerDNS',
                'running' => $serverStatus['running'] ?? false,
                'version' => $serverStatus['version'] ?? 'unknown'
            ];
        }

        // Determine if this is a limited user (can edit own profile but not view/edit others)
        $isLimitedUser = $permissions['user_edit_own'] &&
                        !$permissions['user_view_others'] &&
                        !$permissions['user_edit_others'];

        // Determine if user can change password (internal auth only, not ldap/oidc/saml)
        $canChangePassword = !in_array($this->userContextService->getAuthMethod(), ['ldap', 'oidc', 'saml']);

        $this->render("index.html", [
            'user_name' => $this->userContextService->getDisplayName(),
            'auth_used' => $this->userContextService->getAuthMethod() ?? '',
            'can_change_password' => $canChangePassword,
            'permissions' => $permissions,
            'dblog_use' => $this->config->get('logging', 'database_enabled', false),
            'iface_add_reverse_record' => $this->config->get('interface', 'add_reverse_record', true),
            'api_enabled' => $this->config->get('api', 'enabled', false),
            'pdns_api_enabled' => $pdnsApiEnabled,
            'show_pdns_status' => $showPdnsStatus,
            'pdns_server_status' => $pdnsServerStatus,
            'is_limited_user' => $isLimitedUser,
            'user_id' => $userId,
            'enable_consistency_checks' => $this->config->get('interface', 'enable_consistency_checks', false),
            'module_nav_items' => $this->getModuleNavItemsForDashboard(),
        ]);
    }

    private function getModuleNavItemsForDashboard(): array
    {
        $registry = new ModuleRegistry($this->config);
        $registry->loadModules();

        $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $items = $registry->getNavItems($isAdmin);

        return array_values(array_filter($items, function (array $item): bool {
            if (!empty($item['permission'])) {
                return UserManager::verifyPermission($this->db, $item['permission']);
            }
            return true;
        }));
    }
}
