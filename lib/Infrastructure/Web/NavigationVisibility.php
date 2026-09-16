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

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

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
        $ueberuser = $can('user_is_ueberuser');
        $viewZones = $can('zone_content_view_own') || $can('zone_content_view_others');
        $viewZoneLogs = $can('zone_logs_view_own') || $can('zone_logs_view_others');
        $zoneLogs = ($ueberuser || $viewZoneLogs) && $dbLog;
        $userList = $can('user_view_others') || $can('user_edit_others') || $can('user_add_new') || $ueberuser;
        $groups = ($ueberuser || ($can('group_logs_view') && $dbLog))
            && (bool)$config->get('permissions', 'show_group_access_templates', true);
        $apiKeys = ($ueberuser || $can('api_manage_keys')) && $apiEnabled;
        $consistency = $ueberuser && (bool)$config->get('interface', 'enable_consistency_checks', false);

        return [
            'search' => $can('search'),
            'zones' => $viewZones || $can('zone_master_add') || $can('zone_slave_add') || ($viewZoneLogs && $dbLog),
            'zone_list' => $viewZones,
            'zone_add_master' => $can('zone_master_add'),
            'zone_add_slave' => $can('zone_slave_add'),
            'bulk_registration' => $can('zone_master_add'),
            // The batch PTR page itself requires an edit grant.
            'batch_ptr' => (bool)$config->get('interface', 'add_reverse_record', false)
                && ($can('zone_content_edit_own') || $can('zone_content_edit_others')),
            'zone_logs' => $zoneLogs,
            'record_changes' => $zoneLogs && $ueberuser,
            'users' => $userList || ($can('user_logs_view') && $dbLog),
            'user_list' => $userList,
            'user_add' => $can('user_add_new'),
            'user_logs' => ($ueberuser || $can('user_logs_view')) && $dbLog,
            'groups' => $groups,
            'group_manage' => $groups && $ueberuser,
            'group_logs' => $groups && $dbLog,
            'permissions' => $can('templ_perm_edit'),
            'perm_templ_add' => $can('templ_perm_add'),
            'templates' => $can('zone_templ_add') || $can('zone_templ_edit'),
            'zone_templ_add' => $can('zone_templ_add'),
            'tools' => $apiKeys || $consistency || $hasModuleItems,
            'api_keys' => $apiKeys,
            'api_logs' => $ueberuser && $apiEnabled && $dbLog,
            'api_docs' => $apiEnabled && (bool)$config->get('api', 'docs_enabled', false),
            'database_consistency' => $consistency,
        ];
    }
}
