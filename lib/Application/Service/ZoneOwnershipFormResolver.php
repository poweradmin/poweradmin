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

namespace Poweradmin\Application\Service;

use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Service\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\ZoneOwnershipResolution;

/**
 * Reads the owner and group fields of the add-zone forms and applies the
 * shared ownership rules. The forms drop what the ownership mode disallows
 * instead of rejecting it, and word the refusals in the user's language.
 */
class ZoneOwnershipFormResolver
{
    public function __construct(
        private readonly ZoneOwnershipModeService $mode,
        private readonly ZoneCreateOwnershipResolver $resolver
    ) {
    }

    /**
     * The page-level refusal for a user who could not pick any owner, or null.
     */
    public function blocker(int $callerUserId): ?string
    {
        return match ($this->resolver->ownerOptionsBlocker($callerUserId)) {
            ZoneOwnershipResolution::NO_GROUPS_EXIST => _('Zone ownership mode is groups_only but no groups exist. Create a group before adding zones.'),
            ZoneOwnershipResolution::NOT_IN_ANY_GROUP => _('Zone ownership mode is groups_only but you are not a member of any group. Ask an administrator to add you to a group before creating zones.'),
            default => null,
        };
    }

    public function resolve(Request $request, int $callerUserId): ZoneOwnershipResolution
    {
        return $this->resolveInputs($request->getPostParam('owner'), $request->getPostParam('groups'), $callerUserId);
    }

    /**
     * @param mixed $ownerInput The posted owner field, if any
     * @param mixed $groupsInput The posted groups field, if any
     */
    public function resolveInputs(mixed $ownerInput, mixed $groupsInput, int $callerUserId): ZoneOwnershipResolution
    {
        // An empty, zero or malformed owner means "no user owner", as zones.owner=0 does elsewhere.
        $ownerId = is_scalar($ownerInput) ? filter_var($ownerInput, FILTER_VALIDATE_INT) : false;
        $owner = $this->mode->isUserOwnerAllowed() && $ownerId !== false && $ownerId > 0 ? $ownerId : null;

        $groupIds = $this->mode->isGroupOwnerAllowed() && is_array($groupsInput) ? array_map('intval', $groupsInput) : [];

        return $this->resolver->resolveOwnership($owner, $groupIds, $callerUserId);
    }

    /**
     * The refusal in the user's language; the resolution's own text is the API wording.
     */
    public static function errorMessage(ZoneOwnershipResolution $resolution): string
    {
        return match ($resolution->code) {
            ZoneOwnershipResolution::NO_OWNER => _('At least one user or group must be selected as owner.'),
            ZoneOwnershipResolution::OTHER_OWNER_FORBIDDEN => _('You do not have permission to create zones for other users.'),
            ZoneOwnershipResolution::UNKNOWN_OWNER => sprintf(_('Unknown user ID: %s'), implode(',', $resolution->ids)),
            ZoneOwnershipResolution::UNKNOWN_GROUPS => sprintf(_('Unknown group ID(s): %s'), implode(',', $resolution->ids)),
            ZoneOwnershipResolution::GROUPS_NOT_MEMBER => sprintf(_('You can only assign groups you are a member of (disallowed: %s)'), implode(',', $resolution->ids)),
            default => (string)$resolution->error,
        };
    }
}
