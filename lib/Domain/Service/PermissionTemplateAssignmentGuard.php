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
 * Gates `perm_templ` assignment on user create/update API paths.
 *
 * Without this gate, any caller with `user_add_new` could create a new ueberuser
 * by supplying `perm_templ` equal to the Administrator template id, and any
 * caller with `user_edit_own` could self-elevate via PUT /users/{self}. Mirrors
 * the web UI policy: ueberusers and holders of `user_edit_templ_perm` may choose
 * any template; everyone else gets the supplied value rejected or, when omitted,
 * a safe minimal-template default in place of the repository's historical
 * fallback to template id 1 (Administrator).
 */
class PermissionTemplateAssignmentGuard
{
    public const REJECT_MESSAGE = 'Setting perm_templ requires user_edit_templ_perm or user_is_ueberuser';

    /**
     * Apply the gate to a create/update input array.
     *
     * @param ?int $defaultUserTemplateId Minimum-privilege template to inject when
     *                                    the caller cannot choose; null leaves the
     *                                    input untouched (suited to update paths).
     * @param array<string, mixed> $input Mutated in place when defaulting.
     * @return ?string Error message to surface as 403, or null if the input passes.
     */
    public static function apply(
        ApiPermissionService $permissionService,
        ?int $defaultUserTemplateId,
        int $callerId,
        array &$input
    ): ?string {
        $canAssignTemplate = $permissionService->userHasPermission($callerId, 'user_is_ueberuser')
            || $permissionService->userHasPermission($callerId, 'user_edit_templ_perm');

        if ($canAssignTemplate) {
            return null;
        }

        if (array_key_exists('perm_templ', $input) && $input['perm_templ'] !== null) {
            return self::REJECT_MESSAGE;
        }

        if ($defaultUserTemplateId !== null) {
            $input['perm_templ'] = $defaultUserTemplateId;
        }

        return null;
    }
}
