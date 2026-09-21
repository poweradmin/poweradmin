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

namespace Poweradmin\Domain\Model;

use InvalidArgumentException;

/**
 * The seed data every fresh installation starts from: the perm_items table, the
 * default permission templates, and the default user groups. The installer and
 * the sql/poweradmin-*-db-structure.sql files (via composer sql:seed) both read
 * it, so a new permission or template is added here only.
 */
final class PermissionCatalogue
{
    private const ROLE_ADMINISTRATOR = 'administrator';
    private const ROLE_ZONE_MANAGER = 'zone_manager';
    private const ROLE_EDITOR = 'editor';
    private const ROLE_VIEWER = 'viewer';
    private const ROLE_GUEST = 'guest';

    /**
     * perm_templ_items rows were appended to the schema files permission by
     * permission after this id, so numbering follows that order to stay diffable.
     */
    private const LAST_ORIGINAL_TEMPLATE_PERMISSION_ID = 67;

    /**
     * perm_items rows as [id, name, descr]. Ids are part of the schema (66 was
     * retired and is never reused).
     *
     * @return array<int, array{0: int, 1: string, 2: string}>
     */
    public static function permissions(): array
    {
        return [
            [41, Permission::PERM_ZONE_MASTER_ADD, 'User is allowed to add new master zones.'],
            [42, Permission::PERM_ZONE_SLAVE_ADD, 'User is allowed to add new slave zones.'],
            [43, Permission::PERM_ZONE_CONTENT_VIEW_OWN, 'User is allowed to see the content of zones he owns.'],
            [44, Permission::PERM_ZONE_CONTENT_EDIT_OWN, 'User is allowed to edit the content of zones he owns.'],
            [45, Permission::PERM_ZONE_META_EDIT_OWN, 'User is allowed to edit the meta data of zones he owns.'],
            [46, Permission::PERM_ZONE_CONTENT_VIEW_OTHERS, 'User is allowed to see the content of zones he does not own.'],
            [47, Permission::PERM_ZONE_CONTENT_EDIT_OTHERS, 'User is allowed to edit the content of zones he does not own.'],
            [48, Permission::PERM_ZONE_META_EDIT_OTHERS, 'User is allowed to edit the meta data of zones he does not own.'],
            [49, Permission::PERM_SEARCH, 'User is allowed to perform searches.'],
            [50, Permission::PERM_SUPERMASTER_VIEW, 'User is allowed to view supermasters.'],
            [51, Permission::PERM_SUPERMASTER_ADD, 'User is allowed to add new supermasters.'],
            [52, Permission::PERM_SUPERMASTER_EDIT, 'User is allowed to edit supermasters.'],
            [53, Permission::PERM_USER_IS_UEBERUSER, 'User has full access. God-like. Redeemer.'],
            [54, Permission::PERM_USER_VIEW_OTHERS, 'User is allowed to see other users and their details.'],
            [55, Permission::PERM_USER_ADD_NEW, 'User is allowed to add new users.'],
            [56, Permission::PERM_USER_EDIT_OWN, 'User is allowed to edit their own details.'],
            [57, Permission::PERM_USER_EDIT_OTHERS, 'User is allowed to edit other users.'],
            [58, Permission::PERM_USER_PASSWD_EDIT_OTHERS, 'User is allowed to edit the password of other users.'],
            [59, Permission::PERM_USER_EDIT_TEMPL_PERM, 'User is allowed to change the permission template that is assigned to a user.'],
            [60, Permission::PERM_TEMPL_PERM_ADD, 'User is allowed to add new permission templates.'],
            [61, Permission::PERM_TEMPL_PERM_EDIT, 'User is allowed to edit existing permission templates.'],
            [62, Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT, 'User is allowed to edit record, but not SOA and NS.'],
            [63, Permission::PERM_ZONE_TEMPL_ADD, 'User is allowed to add new zone templates.'],
            [64, Permission::PERM_ZONE_TEMPL_EDIT, 'User is allowed to edit existing zone templates.'],
            [65, Permission::PERM_API_MANAGE_KEYS, 'User is allowed to create and manage API keys.'],
            [67, Permission::PERM_ZONE_DELETE_OWN, 'User is allowed to delete zones they own.'],
            [68, Permission::PERM_ZONE_DELETE_OTHERS, 'User is allowed to delete zones owned by others.'],
            [69, Permission::PERM_USER_ENFORCE_MFA, 'User is required to use multi-factor authentication.'],
            [70, Permission::PERM_ZONE_DNSSEC_MANAGE_OWN, 'User is allowed to manage DNSSEC keys for zones he owns.'],
            [71, Permission::PERM_ZONE_LOGS_VIEW_OWN, 'User is allowed to view activity logs for zones he owns.'],
            [72, Permission::PERM_ZONE_LOGS_VIEW_OTHERS, 'User is allowed to view activity logs for zones he does not own.'],
            [73, Permission::PERM_USER_LOGS_VIEW, 'User is allowed to view the user activity logs.'],
            [74, Permission::PERM_GROUP_LOGS_VIEW, 'User is allowed to view the group activity logs.'],
            [75, Permission::PERM_EDIT_NS_SUBZONE, 'User is allowed to edit NS records below the zone apex, but not SOA and apex NS records.'],
            [76, Permission::PERM_ZONE_METADATA_VIEW_OWN, 'User is allowed to see the meta data of zones he owns.'],
            [77, Permission::PERM_ZONE_METADATA_VIEW_OTHERS, 'User is allowed to see the meta data of zones he does not own.'],
            [78, Permission::PERM_ZONE_OWNERSHIP_VIEW_OWN, 'User is allowed to see the owners of zones he owns.'],
            [79, Permission::PERM_ZONE_OWNERSHIP_VIEW_OTHERS, 'User is allowed to see the owners of zones he does not own.'],
            [80, Permission::PERM_ZONE_CHANGE_REQUEST_OWN, 'User is allowed to request changes to zones they own'],
            [81, Permission::PERM_ZONE_CHANGE_REQUEST_OTHERS, 'User is allowed to request changes to any zone'],
            [82, Permission::PERM_ZONE_CHANGE_APPROVE_OWN, 'User is allowed to review change requests for zones they own'],
            [83, Permission::PERM_ZONE_CHANGE_APPROVE_OTHERS, 'User is allowed to review change requests for any zone'],
        ];
    }

    public static function permissionId(string $name): int
    {
        foreach (self::permissions() as [$id, $permissionName]) {
            if ($permissionName === $name) {
                return $id;
            }
        }

        throw new InvalidArgumentException("Unknown permission: $name");
    }

    /**
     * Default perm_templ rows: five user templates, then their group twins with
     * the same permissions. Keys: id, name, descr, template_type, permissions.
     *
     * @return array<int, array{id: int, name: string, descr: string, template_type: string, permissions: array<int, string>}>
     */
    public static function templates(): array
    {
        $rows = [
            [1, 'Administrator', 'Administrator template with full rights.', 'user', self::ROLE_ADMINISTRATOR],
            [2, 'Zone Manager', 'Full management of own zones including creation, editing, deletion, and templates.', 'user', self::ROLE_ZONE_MANAGER],
            [3, 'Editor', 'Edit own zone records but cannot modify SOA and NS records.', 'user', self::ROLE_EDITOR],
            [4, 'Viewer', 'Read-only access to own zones with search capability.', 'user', self::ROLE_VIEWER],
            [5, 'Guest', 'Temporary access with no permissions. Suitable for users awaiting approval or limited access.', 'user', self::ROLE_GUEST],
            [6, 'Administrators', 'Full administrative access for group members.', 'group', self::ROLE_ADMINISTRATOR],
            [7, 'Zone Managers', 'Full zone management for group members.', 'group', self::ROLE_ZONE_MANAGER],
            [8, 'Editors', 'Edit zone records (no SOA/NS) for group members.', 'group', self::ROLE_EDITOR],
            [9, 'Viewers', 'Read-only zone access for group members.', 'group', self::ROLE_VIEWER],
            [10, 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', 'group', self::ROLE_GUEST],
        ];

        return array_map(fn(array $row) => [
            'id' => $row[0],
            'name' => $row[1],
            'descr' => $row[2],
            'template_type' => $row[3],
            'permissions' => self::rolePermissions($row[4]),
        ], $rows);
    }

    /**
     * @return array<int, array{id: int, name: string, descr: string, template_type: string, permissions: array<int, string>}>
     */
    public static function templatesOfType(string $templateType): array
    {
        return array_values(array_filter(
            self::templates(),
            fn(array $template) => $template['template_type'] === $templateType
        ));
    }

    /**
     * perm_templ_items rows as [id, templ_id, perm_id], numbered in the order the
     * schema files accumulated them: the original set template by template, then
     * each later permission across all templates.
     *
     * @return array<int, array{0: int, 1: int, 2: int}>
     */
    public static function templateItems(): array
    {
        $pairs = [];
        foreach (self::templates() as $template) {
            foreach ($template['permissions'] as $permissionName) {
                $pairs[] = [$template['id'], self::permissionId($permissionName)];
            }
        }

        usort($pairs, function (array $a, array $b): int {
            $waveA = $a[1] > self::LAST_ORIGINAL_TEMPLATE_PERMISSION_ID ? $a[1] : 0;
            $waveB = $b[1] > self::LAST_ORIGINAL_TEMPLATE_PERMISSION_ID ? $b[1] : 0;
            return [$waveA, $a[0], $a[1]] <=> [$waveB, $b[0], $b[1]];
        });

        $rows = [];
        foreach ($pairs as $index => [$templateId, $permissionId]) {
            $rows[] = [$index + 1, $templateId, $permissionId];
        }

        return $rows;
    }

    /**
     * Default user_groups rows, each backed by the group template of the same name.
     *
     * @return array<int, array{id: int, name: string, description: string, template: string}>
     */
    public static function groups(): array
    {
        return [
            ['id' => 1, 'name' => 'Administrators', 'description' => 'Full administrative access to all system functions.', 'template' => 'Administrators'],
            ['id' => 2, 'name' => 'Zone Managers', 'description' => 'Full zone management including creation, editing, and deletion.', 'template' => 'Zone Managers'],
            ['id' => 3, 'name' => 'Editors', 'description' => 'Edit zone records but cannot modify SOA and NS records.', 'template' => 'Editors'],
            ['id' => 4, 'name' => 'Viewers', 'description' => 'Read-only access to zones with search capability.', 'template' => 'Viewers'],
            ['id' => 5, 'name' => 'Guests', 'description' => 'Temporary group with no permissions. Suitable for users awaiting approval.', 'template' => 'Guests'],
        ];
    }

    public static function templateId(string $name): int
    {
        foreach (self::templates() as $template) {
            if ($template['name'] === $name) {
                return $template['id'];
            }
        }

        throw new InvalidArgumentException("Unknown permission template: $name");
    }

    /**
     * @return array<int, string>
     */
    private static function rolePermissions(string $role): array
    {
        return match ($role) {
            self::ROLE_ADMINISTRATOR => [Permission::PERM_USER_IS_UEBERUSER],
            self::ROLE_ZONE_MANAGER => [
                Permission::PERM_ZONE_MASTER_ADD,
                Permission::PERM_ZONE_SLAVE_ADD,
                Permission::PERM_ZONE_CONTENT_VIEW_OWN,
                Permission::PERM_ZONE_CONTENT_EDIT_OWN,
                Permission::PERM_ZONE_META_EDIT_OWN,
                Permission::PERM_SEARCH,
                Permission::PERM_USER_EDIT_OWN,
                Permission::PERM_ZONE_TEMPL_ADD,
                Permission::PERM_ZONE_TEMPL_EDIT,
                Permission::PERM_API_MANAGE_KEYS,
                Permission::PERM_ZONE_DELETE_OWN,
                Permission::PERM_ZONE_DNSSEC_MANAGE_OWN,
                Permission::PERM_ZONE_LOGS_VIEW_OWN,
                Permission::PERM_ZONE_METADATA_VIEW_OWN,
                Permission::PERM_ZONE_OWNERSHIP_VIEW_OWN,
            ],
            self::ROLE_EDITOR => [
                Permission::PERM_ZONE_CONTENT_VIEW_OWN,
                Permission::PERM_SEARCH,
                Permission::PERM_USER_EDIT_OWN,
                Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT,
                Permission::PERM_ZONE_LOGS_VIEW_OWN,
                Permission::PERM_ZONE_METADATA_VIEW_OWN,
                Permission::PERM_ZONE_OWNERSHIP_VIEW_OWN,
            ],
            self::ROLE_VIEWER => [
                Permission::PERM_ZONE_CONTENT_VIEW_OWN,
                Permission::PERM_SEARCH,
                Permission::PERM_ZONE_LOGS_VIEW_OWN,
                Permission::PERM_ZONE_METADATA_VIEW_OWN,
                Permission::PERM_ZONE_OWNERSHIP_VIEW_OWN,
            ],
            self::ROLE_GUEST => [],
            default => throw new InvalidArgumentException("Unknown template role: $role"),
        };
    }
}
