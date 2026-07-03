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
 * Gates auth-critical fields on user self-edit API paths (#1327).
 *
 * A caller with only `user_edit_own` may edit their contact fields but not
 * username (login/audit identity), use_ldap (disabling it converts the
 * account to local auth), or active. Unchanged values pass so GET->PUT
 * round-tripping clients keep working.
 */
class SelfEditFieldGuard
{
    /** Fields a self-editor without user_edit_others may not change. */
    public const RESTRICTED_FIELDS = ['username', 'use_ldap', 'active'];

    /**
     * Apply the gate to an update input array.
     *
     * @param array<string, mixed> $currentUser Stored users row for the target
     *                                          (expects username, use_ldap, active).
     * @param array<string, mixed> $input Decoded request body.
     * @return ?string Error message to surface as 403, or null if the input passes.
     */
    public static function apply(
        ApiPermissionService $permissionService,
        int $callerId,
        int $targetUserId,
        array $currentUser,
        array $input
    ): ?string {
        if ($callerId !== $targetUserId) {
            return null;
        }

        if (
            $permissionService->userHasPermission($callerId, 'user_is_ueberuser')
            || $permissionService->userHasPermission($callerId, 'user_edit_others')
        ) {
            return null;
        }

        $blocked = [];
        foreach (self::RESTRICTED_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            // Present null is a change attempt: the repository updates every
            // present key and casts null to 0.
            if ($input[$field] === null || self::differs($field, $input[$field], $currentUser[$field] ?? null)) {
                $blocked[] = $field;
            }
        }

        if ($blocked !== []) {
            return sprintf(
                'Changing %s on your own account requires user_edit_others or user_is_ueberuser',
                implode(', ', $blocked)
            );
        }

        return null;
    }

    private static function differs(string $field, mixed $submitted, mixed $stored): bool
    {
        if ($field === 'username') {
            return (string)$submitted !== (string)$stored;
        }

        // Compare as the repository persists these fields - an (int) cast -
        // so e.g. "true" (casts to 0) can't pass as unchanged.
        return (int)$submitted !== (int)$stored;
    }
}
