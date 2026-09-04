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
 * the web UI policy: a caller may only choose a template that stays within the
 * authority they already hold, and retemplating their own account additionally
 * requires `user_edit_others`; everyone else gets the supplied value rejected
 * or, when omitted, a safe minimal-template default in place of the repository's
 * historical fallback to template id 1 (Administrator).
 */
class PermissionTemplateAssignmentGuard
{
    /**
     * Apply the gate to a create/update input array.
     *
     * @param ?int $defaultUserTemplateId Minimum-privilege template to inject when
     *                                    the caller cannot choose; null leaves the
     *                                    input untouched (suited to update paths).
     * @param array<string, mixed> $input Mutated in place when defaulting.
     * @param ?int $targetUserId Account being written; null on the create path,
     *                           where no account exists to self-elevate yet.
     * @return ?string Error message to surface as 403, or null if the input passes.
     */
    public static function apply(
        ApiPermissionService $permissionService,
        ?int $defaultUserTemplateId,
        int $callerId,
        array &$input,
        ?int $targetUserId
    ): ?string {
        $providesTemplate = array_key_exists('perm_templ', $input) && $input['perm_templ'] !== null;

        if ($providesTemplate) {
            return $permissionService->checkPermissionTemplateAssignment(
                $callerId,
                $targetUserId,
                (int)$input['perm_templ']
            );
        }

        // No template supplied: on the create path default to the minimal template
        // rather than the repository's historical Administrator (id 1) fallback -
        // otherwise a privileged caller omitting perm_templ silently creates a
        // super admin. On the update path the default is null and nothing changes.
        if ($defaultUserTemplateId !== null) {
            $input['perm_templ'] = $defaultUserTemplateId;
        }

        return null;
    }
}
