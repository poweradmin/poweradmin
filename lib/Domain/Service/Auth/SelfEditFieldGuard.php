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
        PermissionService $permissionService,
        int $callerId,
        int $targetUserId,
        array $currentUser,
        array $input
    ): ?string {
        if ($callerId !== $targetUserId) {
            return null;
        }

        if (
            $permissionService->hasPermission($callerId, Permission::PERM_USER_EDIT_OTHERS)
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

    /**
     * Whether a submitted username or email differs from the stored one. Either
     * one moves where a password reset mail goes or which name signs in, so the
     * caller needs the right to set that account's password.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $submitted
     */
    public static function changesSignInIdentity(array $stored, array $submitted): bool
    {
        foreach (['username', 'email'] as $field) {
            if (!array_key_exists($field, $submitted)) {
                continue;
            }
            if (!is_scalar($submitted[$field]) || (string)$submitted[$field] !== (string)($stored[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private static function differs(string $field, mixed $submitted, mixed $stored): bool
    {
        if ($field === 'username') {
            return (string)$submitted !== (string)$stored;
        }

        if ($field === 'use_ldap') {
            // Parsed as UserCommandFactory::useLdap() writes it; unparseable counts as a change
            $parsed = filter_var($submitted, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $parsed === null || $parsed !== ((int)$stored !== 0);
        }

        // active is persisted by an (int) cast (UserCommandFactory::flag()), so "true" counts as 0
        return (int)$submitted !== (int)$stored;
    }
}
