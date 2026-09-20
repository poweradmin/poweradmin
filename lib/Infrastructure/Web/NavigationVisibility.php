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

namespace Poweradmin\Infrastructure\Web;

use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Config\ConfigurationInterface;

/**
 * Decides once per request which navigation entries the current user gets, so
 * the two theme headers and the dashboard share one rule per entry.
 */
final class NavigationVisibility
{
    /**
     * @param callable(string): bool $can Permission check for the current user
     * @param bool $hasModuleItems Whether any module contributes a Tools entry
     * @return array<string, bool>
     */
    public static function build(callable $can, ConfigurationInterface $config, bool $hasModuleItems): array
    {
        $dbLog = (bool)$config->get('logging', 'database_enabled');
        $apiEnabled = (bool)$config->get('api', 'enabled', false);
        $ueberuser = $can(Permission::PERM_USER_IS_UEBERUSER);
        $viewZones = $can(Permission::PERM_ZONE_CONTENT_VIEW_OWN) || $can(Permission::PERM_ZONE_CONTENT_VIEW_OTHERS);
        $viewZoneLogs = $can(Permission::PERM_ZONE_LOGS_VIEW_OWN) || $can(Permission::PERM_ZONE_LOGS_VIEW_OTHERS);
        $zoneLogs = ($ueberuser || $viewZoneLogs) && $dbLog;
        $userList = $can(Permission::PERM_USER_VIEW_OTHERS) || $can(Permission::PERM_USER_EDIT_OTHERS) || $can(Permission::PERM_USER_ADD_NEW) || $ueberuser;
        $groups = ($ueberuser || ($can(Permission::PERM_GROUP_LOGS_VIEW) && $dbLog))
            && (bool)$config->get('permissions', 'show_group_access_templates', true);
        $apiKeys = ($ueberuser || $can(Permission::PERM_API_MANAGE_KEYS)) && $apiEnabled;
        $consistency = $ueberuser && (bool)$config->get('interface', 'enable_consistency_checks', false);
        $changeRequests = (bool)$config->get('approval', 'enabled', false)
            && ($ueberuser || $can(Permission::PERM_ZONE_CHANGE_REQUEST_OWN) || $can(Permission::PERM_ZONE_CHANGE_REQUEST_OTHERS)
                || $can(Permission::PERM_ZONE_CHANGE_APPROVE_OWN) || $can(Permission::PERM_ZONE_CHANGE_APPROVE_OTHERS));

        return [
            'search' => $can(Permission::PERM_SEARCH),
            'zones' => $viewZones || $can(Permission::PERM_ZONE_MASTER_ADD) || $can(Permission::PERM_ZONE_SLAVE_ADD) || ($viewZoneLogs && $dbLog) || $changeRequests,
            'zone_list' => $viewZones,
            'zone_add_master' => $can(Permission::PERM_ZONE_MASTER_ADD),
            'zone_add_slave' => $can(Permission::PERM_ZONE_SLAVE_ADD),
            'bulk_registration' => $can(Permission::PERM_ZONE_MASTER_ADD),
            // The batch PTR page itself requires an edit grant.
            'batch_ptr' => (bool)$config->get('interface', 'add_reverse_record', false)
                && ($can(Permission::PERM_ZONE_CONTENT_EDIT_OWN) || $can(Permission::PERM_ZONE_CONTENT_EDIT_OTHERS)),
            'zone_logs' => $zoneLogs,
            'record_changes' => $zoneLogs && $ueberuser,
            'change_requests' => $changeRequests,
            'users' => $userList || ($can(Permission::PERM_USER_LOGS_VIEW) && $dbLog),
            'user_list' => $userList,
            'user_add' => $can(Permission::PERM_USER_ADD_NEW),
            'user_logs' => ($ueberuser || $can(Permission::PERM_USER_LOGS_VIEW)) && $dbLog,
            'groups' => $groups,
            'group_manage' => $groups && $ueberuser,
            'group_logs' => $groups && $dbLog,
            'permissions' => $can(Permission::PERM_TEMPL_PERM_EDIT),
            'perm_templ_add' => $can(Permission::PERM_TEMPL_PERM_ADD),
            'templates' => $can(Permission::PERM_ZONE_TEMPL_ADD) || $can(Permission::PERM_ZONE_TEMPL_EDIT),
            Permission::PERM_ZONE_TEMPL_ADD => $can(Permission::PERM_ZONE_TEMPL_ADD),
            'tools' => $apiKeys || $consistency || $hasModuleItems,
            'api_keys' => $apiKeys,
            'api_logs' => $ueberuser && $apiEnabled && $dbLog,
            'api_docs' => $apiEnabled && (bool)$config->get('api', 'docs_enabled', false),
            'database_consistency' => $consistency,
        ];
    }
}
