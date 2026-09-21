<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Application\Controller\System;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\OidcConfigurationService;
use Poweradmin\Application\Service\PowerdnsStatusService;
use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Enum\AuthMethod;

/**
 * Renders the dashboard after login with the cards allowed by the user's permissions.
 */
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

        $permissions = $this->services()->permissionService()->getPermissionFlags((int)$userId, [
            Permission::PERM_SEARCH,
            Permission::PERM_ZONE_CONTENT_VIEW_OWN,
            Permission::PERM_ZONE_CONTENT_VIEW_OTHERS,
            Permission::PERM_ZONE_CONTENT_EDIT_OWN,
            Permission::PERM_ZONE_CONTENT_EDIT_OTHERS,
            Permission::PERM_SUPERMASTER_VIEW,
            Permission::PERM_ZONE_MASTER_ADD,
            Permission::PERM_ZONE_SLAVE_ADD,
            Permission::PERM_SUPERMASTER_ADD,
            Permission::PERM_USER_IS_UEBERUSER,
            Permission::PERM_TEMPL_PERM_EDIT,
            Permission::PERM_ZONE_TEMPL_ADD,
            Permission::PERM_ZONE_TEMPL_EDIT,
            Permission::PERM_USER_VIEW_OTHERS,
            Permission::PERM_USER_EDIT_OWN,
            Permission::PERM_USER_EDIT_OTHERS,
            Permission::PERM_USER_ADD_NEW,
            Permission::PERM_API_MANAGE_KEYS,
            Permission::PERM_ZONE_LOGS_VIEW_OWN,
            Permission::PERM_ZONE_LOGS_VIEW_OTHERS,
            Permission::PERM_USER_LOGS_VIEW,
            Permission::PERM_GROUP_LOGS_VIEW,
        ]);

        // Check PowerDNS server status if API is enabled and user is admin
        $pdnsServerStatus = null;
        $pdnsApiEnabled = !empty($this->config->get('pdns_api', 'url', '')) && !empty($this->config->get('pdns_api', 'key', ''));
        $showPdnsStatus = $this->config->get('interface', 'show_pdns_status', false);

        if ($pdnsApiEnabled && $showPdnsStatus && $permissions[Permission::PERM_USER_IS_UEBERUSER]) {
            $statusService = new PowerdnsStatusService($this->config);
            $serverStatus = $statusService->getServerStatus();
            $pdnsServerStatus = [
                'display' => $serverStatus['display_name'] ?? 'PowerDNS',
                'running' => $serverStatus['running'] ?? false,
                'version' => $serverStatus['version'] ?? 'unknown'
            ];
        }

        // Determine if this is a limited user (can edit own profile but not view/edit others)
        $isLimitedUser = $permissions[Permission::PERM_USER_EDIT_OWN] &&
                        !$permissions[Permission::PERM_USER_VIEW_OTHERS] &&
                        !$permissions[Permission::PERM_USER_EDIT_OTHERS];

        // Determine if user can change password (internal auth only, not ldap/oidc/saml)
        $canChangePassword = AuthMethod::fromDb($this->userContextService->getAuthMethod())->allowsLocalPassword();

        // Dashboard stats for admin users
        $dashboardStats = null;
        $showDashboardStats = $this->config->get('interface', 'show_dashboard_stats', true);
        if ($permissions[Permission::PERM_USER_IS_UEBERUSER] && $showDashboardStats) {
            $dashboardStats = $this->services()->dashboardStatsService()->stats((int)$this->getCurrentUserId(), $this->hasPermission(Permission::PERM_USER_VIEW_OTHERS));
        }

        // Dashboard owns the version refresh so other pages read from cache only.
        // Runs whenever pdns_api is configured (SQL dashboards show the version)
        // and for every user - non-admin sessions need the capability snapshot too.
        if ($pdnsApiEnabled) {
            $this->refreshPdnsCapabilities();
        }

        // Surface the otherwise-silent misconfiguration: with application_url
        // unset, reset emails are skipped with only a server log entry.
        $passwordResetMisconfigured = $permissions[Permission::PERM_USER_IS_UEBERUSER]
            && $this->config->get('security', 'password_reset.enabled', false)
            && empty($this->config->get('interface', 'application_url', ''));

        $ssoTemplateMisconfigured = $permissions[Permission::PERM_USER_IS_UEBERUSER]
            && $this->ssoProvisioningTemplateMissing();

        $dblogUse = $this->config->get('logging', 'database_enabled', false);
        $showGroupAccessTemplates = (bool)$this->config->get('permissions', 'show_group_access_templates', true);
        $ifaceAddReverseRecord = $this->config->get('interface', 'add_reverse_record', true);
        $apiEnabled = $this->config->get('api', 'enabled', false);
        $enableConsistencyChecks = $this->config->get('interface', 'enable_consistency_checks', false);
        $moduleNavItems = $this->getModuleNavItemsForDashboard();

        $hasDnsManagement = ($permissions[Permission::PERM_USER_IS_UEBERUSER] && $pdnsApiEnabled && $showPdnsStatus)
            || $permissions[Permission::PERM_SEARCH]
            || $permissions[Permission::PERM_ZONE_CONTENT_VIEW_OWN] || $permissions[Permission::PERM_ZONE_CONTENT_VIEW_OTHERS]
            || $permissions[Permission::PERM_ZONE_TEMPL_ADD] || $permissions[Permission::PERM_ZONE_TEMPL_EDIT]
            || $permissions[Permission::PERM_SUPERMASTER_VIEW];
        $hasZoneOperations = $permissions[Permission::PERM_ZONE_MASTER_ADD]
            || $permissions[Permission::PERM_ZONE_SLAVE_ADD]
            || $permissions[Permission::PERM_SUPERMASTER_ADD]
            || ($ifaceAddReverseRecord && ($permissions[Permission::PERM_ZONE_CONTENT_EDIT_OWN] || $permissions[Permission::PERM_ZONE_CONTENT_EDIT_OTHERS]));
        $hasAdministration = $permissions[Permission::PERM_USER_VIEW_OTHERS] || $permissions[Permission::PERM_USER_EDIT_OTHERS]
            || $permissions[Permission::PERM_USER_ADD_NEW] || $permissions[Permission::PERM_USER_IS_UEBERUSER]
            || $permissions[Permission::PERM_TEMPL_PERM_EDIT]
            || ($dblogUse && ($permissions[Permission::PERM_ZONE_LOGS_VIEW_OWN] || $permissions[Permission::PERM_ZONE_LOGS_VIEW_OTHERS]
                || $permissions[Permission::PERM_USER_LOGS_VIEW]
                || ($permissions[Permission::PERM_GROUP_LOGS_VIEW] && $showGroupAccessTemplates)));
        $hasTools = ($permissions[Permission::PERM_USER_IS_UEBERUSER] && $enableConsistencyChecks)
            || (($permissions[Permission::PERM_USER_IS_UEBERUSER] || $permissions[Permission::PERM_API_MANAGE_KEYS]) && $apiEnabled)
            || count($moduleNavItems) > 0;

        $this->render("index.html", [
            'dashboard_stats' => $dashboardStats,
            'user_name' => $this->userContextService->getDisplayName(),
            'auth_used' => $this->userContextService->getAuthMethod() ?? '',
            'can_change_password' => $canChangePassword,
            'permissions' => $permissions,
            'dblog_use' => $dblogUse,
            'iface_add_reverse_record' => $ifaceAddReverseRecord,
            'api_enabled' => $apiEnabled,
            'pdns_api_enabled' => $pdnsApiEnabled,
            'is_api_backend' => DnsBackendProviderFactory::isApiBackend($this->config),
            'show_pdns_status' => $showPdnsStatus,
            'pdns_server_status' => $pdnsServerStatus,
            'is_limited_user' => $isLimitedUser,
            'user_id' => $userId,
            'enable_consistency_checks' => $enableConsistencyChecks,
            'show_group_access_templates' => $showGroupAccessTemplates,
            'module_nav_items' => $moduleNavItems,
            'has_dns_management' => $hasDnsManagement,
            'has_zone_operations' => $hasZoneOperations,
            'has_administration' => $hasAdministration,
            'has_tools' => $hasTools,
            'password_reset_misconfigured' => $passwordResetMisconfigured,
            'sso_template_misconfigured' => $ssoTemplateMisconfigured,
        ]);
    }

    /**
     * Same silent-failure class as the password reset warning: SSO provisioning
     * aborts for any user matching no group mapping when the fallback template
     * is blank, and the login fails with a generic error.
     */
    private function ssoProvisioningTemplateMissing(): bool
    {
        return (new OidcConfigurationService($this->config, $this->logger))->isAutoProvisioningTemplateMissing()
            || (new SamlConfigurationService($this->config, $this->logger))->isAutoProvisioningTemplateMissing();
    }

    private function getModuleNavItemsForDashboard(): array
    {
        $items = $this->moduleRegistry()->getNavItems($this->hasPermission(Permission::PERM_USER_IS_UEBERUSER));

        return array_values(array_filter($items, function (array $item): bool {
            if (!empty($item['permission'])) {
                return $this->hasPermission($item['permission']);
            }
            return true;
        }));
    }
}
