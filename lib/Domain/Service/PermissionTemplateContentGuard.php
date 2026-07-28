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

use Poweradmin\Domain\Repository\UserRepository;

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
    public const UBERUSER_PERMISSION = 'user_is_ueberuser';

    public const CONTENT_SUPERUSER_DENIED = 'Granting user_is_ueberuser in a permission template requires user_is_ueberuser';
    public const EDIT_SUPERUSER_DENIED = 'Editing a permission template that grants user_is_ueberuser requires user_is_ueberuser';

    /**
     * Apply the rule to a template create or update.
     *
     * @param int $callerId Acting user
     * @param ?int $templateId Template being written; null on the create path
     * @param ?array<mixed> $permIds Permission ids the write would persist; null leaves contents untouched
     * @return ?string Error to surface, or null when the write is allowed
     */
    public static function apply(
        UserRepository $userRepository,
        int $callerId,
        ?int $templateId,
        ?array $permIds
    ): ?string {
        // hasAdminPermission() honours group-granted superuser; isUberuser() would not.
        if ($userRepository->hasAdminPermission($callerId)) {
            return null;
        }

        if ($templateId !== null && $userRepository->templateGrantsUberuser($templateId)) {
            return self::EDIT_SUPERUSER_DENIED;
        }

        if ($permIds === null) {
            return null;
        }

        $uberuserPermId = $userRepository->getPermissionIdByName(self::UBERUSER_PERMISSION);
        if ($uberuserPermId === null) {
            return null;
        }

        foreach ($permIds as $permId) {
            // Posted ids arrive as strings; non-scalars would cast to a colliding int.
            if (is_scalar($permId) && (int)$permId === $uberuserPermId) {
                return self::CONTENT_SUPERUSER_DENIED;
            }
        }

        return null;
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
