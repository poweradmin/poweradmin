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

namespace Poweradmin\Domain\Service\Auth;

use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\UserPermissionReadInterface;

/**
 * Gates what a permission template may contain.
 *
 * `templ_perm_add`/`templ_perm_edit` delegate template management, but a template's
 * permission list is itself an authority grant: ticking `user_is_ueberuser` on the
 * template you are already assigned to makes you a superuser without any assignment
 * step, which would bypass PermissionTemplateAssignmentGuard entirely. So a caller who
 * is not already a superuser may neither put that permission into a template nor touch
 * a template that already carries it - the latter also stops them stripping the
 * Administrator template bare to lock real admins out.
 */
class PermissionTemplateContentGuard
{
    public const UBERUSER_PERMISSION = Permission::PERM_USER_IS_UEBERUSER;

    public const CONTENT_SUPERUSER_DENIED = 'Granting user_is_ueberuser in a permission template requires user_is_ueberuser';
    public const EDIT_SUPERUSER_DENIED = 'Editing a permission template that grants user_is_ueberuser requires user_is_ueberuser';
    public const INVALID_PERMISSION_ID = 'Permission ids must be positive integers';
    private const MAX_PERMISSION_ID = 2147483647;

    /**
     * Apply the rule to a template create or update.
     *
     * @param int $callerId Acting user
     * @param ?int $templateId Template being written; null on the create path
     * @param ?array<mixed> $permIds Permission ids the write would persist; null leaves contents untouched
     * @return ?string Error to surface, or null when the write is allowed
     */
    public static function apply(
        UserPermissionReadInterface $userRepository,
        int $callerId,
        ?int $templateId,
        ?array $permIds
    ): ?string {
        // Like isUberuser() this honours group grants; unlike it, it does not also require users.active
        if ($userRepository->hasAdminPermission($callerId)) {
            return null;
        }

        if ($templateId !== null && $userRepository->templateGrantsUberuser($templateId)) {
            return self::EDIT_SUPERUSER_DENIED;
        }

        if ($permIds === null) {
            return null;
        }

        $normalizedIds = self::normalizePermIds($permIds);
        if ($normalizedIds === null) {
            return self::INVALID_PERMISSION_ID;
        }

        $uberuserPermIds = $userRepository->getPermissionIdsByName(self::UBERUSER_PERMISSION);
        if (array_intersect($normalizedIds, $uberuserPermIds) !== []) {
            return self::CONTENT_SUPERUSER_DENIED;
        }

        return null;
    }

    /**
     * Permission ids as ints, or null when any id is not a positive whole number.
     *
     * MySQL rounds a stored '52.5' up to 53 while PHP's (int) truncates it to 52, so an id
     * that is not canonical could be checked as one permission and stored as another.
     *
     * @param array<mixed> $permIds
     * @return ?list<int>
     */
    public static function normalizePermIds(array $permIds): ?array
    {
        $normalized = [];
        foreach ($permIds as $permId) {
            if (is_string($permId) && preg_match('/^[1-9][0-9]{0,9}$/', $permId) === 1) {
                $permId = (int)$permId;
            }
            // perm_templ_items.perm_id is a signed 32-bit column on every backend
            if (!is_int($permId) || $permId < 1 || $permId > self::MAX_PERMISSION_ID) {
                return null;
            }
            $normalized[] = $permId;
        }

        return $normalized;
    }

    /**
     * Drop the superuser permission from a picker list shown to a non-superuser.
     *
     * @param array<int, array<string, mixed>> $permissions Rows of id/name/descr
     * @return array<int, array<string, mixed>>
     */
    public static function filterOfferedPermissions(array $permissions, bool $callerIsSuperuser): array
    {
        if ($callerIsSuperuser) {
            return $permissions;
        }

        $filtered = array_filter(
            $permissions,
            static fn(array $permission): bool => ($permission['name'] ?? '') !== self::UBERUSER_PERMISSION
        );

        return array_values($filtered);
    }
}
