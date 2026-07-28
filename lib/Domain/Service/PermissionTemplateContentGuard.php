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

namespace Poweradmin\Domain\Service;

/**
 * Gates what a permission template may contain.
 *
 * templ_perm_add and templ_perm_edit delegate template management, but a template's
 * permission list is itself an authority grant: ticking user_is_ueberuser on the
 * template you are already assigned to makes you a superuser with no assignment step.
 * Works on permission rows the caller already loaded, so it needs no database access.
 */
class PermissionTemplateContentGuard
{
    public const UBERUSER_PERMISSION = 'user_is_ueberuser';

    public const CONTENT_SUPERUSER_DENIED = 'content_superuser_denied';
    public const EDIT_SUPERUSER_DENIED = 'edit_superuser_denied';

    /**
     * @param array<int, array<string, mixed>> $allPermissions Every row of perm_items
     * @param array<int, array<string, mixed>> $currentPermissions Rows the template holds now; empty when creating
     * @param ?array<mixed> $submittedPermIds Ids the write would persist; null leaves contents untouched
     * @return ?string One of the denial constants, or null when the write is allowed
     */
    public static function apply(
        bool $callerIsSuperuser,
        array $allPermissions,
        array $currentPermissions,
        ?array $submittedPermIds
    ): ?string {
        if ($callerIsSuperuser) {
            return null;
        }

        if (self::containsUberuser($currentPermissions)) {
            return self::EDIT_SUPERUSER_DENIED;
        }

        if ($submittedPermIds === null) {
            return null;
        }

        $uberuserIds = self::uberuserPermissionIds($allPermissions);
        foreach ($submittedPermIds as $permId) {
            // Posted ids arrive as strings; non-scalars would cast to a colliding int.
            if (is_scalar($permId) && in_array((int)$permId, $uberuserIds, true)) {
                return self::CONTENT_SUPERUSER_DENIED;
            }
        }

        return null;
    }

    /**
     * Drop the superuser permission from a picker list shown to a non-superuser.
     *
     * @param array<int, array<string, mixed>> $permissions
     * @return array<int, array<string, mixed>>
     */
    public static function filterOfferedPermissions(array $permissions, bool $callerIsSuperuser): array
    {
        if ($callerIsSuperuser) {
            return $permissions;
        }

        return array_values(array_filter(
            $permissions,
            static fn(array $permission): bool => ($permission['name'] ?? '') !== self::UBERUSER_PERMISSION
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $permissions
     */
    private static function containsUberuser(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (($permission['name'] ?? '') === self::UBERUSER_PERMISSION) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every id carrying the name, since perm_items has no unique constraint on it.
     *
     * @param array<int, array<string, mixed>> $permissions
     * @return array<int, int>
     */
    private static function uberuserPermissionIds(array $permissions): array
    {
        $ids = [];
        foreach ($permissions as $permission) {
            if (($permission['name'] ?? '') === self::UBERUSER_PERMISSION) {
                $ids[] = (int)$permission['id'];
            }
        }

        return $ids;
    }
}
