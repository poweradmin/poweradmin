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

namespace Poweradmin\Application\Service\Zone;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Presenter\OwnerOptionsPresenter;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipResolution;

/**
 * Reads the owner and group fields of the add-zone forms and applies the
 * shared ownership rules. The forms drop what the ownership mode disallows
 * instead of rejecting it, and word the refusals in the user's language.
 * Also decides which users the owner pickers offer.
 */
class ZoneOwnershipFormResolver
{
    public function __construct(
        private readonly ZoneOwnershipModeService $mode,
        private readonly ZoneCreateOwnershipResolver $resolver,
        private readonly PermissionService $permissions
    ) {
    }

    /**
     * The users an owner picker may offer: everyone with user_view_others,
     * otherwise only the caller.
     *
     * @param list<array<string, mixed>> $users Rows with an 'id' key
     * @return list<array<string, mixed>>
     */
    public function selectableOwners(array $users, int $callerUserId): array
    {
        return OwnerOptionsPresenter::offered($this->canViewOthers($callerUserId), $users, $callerUserId);
    }

    /**
     * The users a new zone may be given to: everyone when the create path lets
     * the caller assign other owners and they may see other users, otherwise
     * only the caller.
     *
     * @param list<array<string, mixed>> $users Rows with an 'id' key
     * @return list<array<string, mixed>>
     */
    public function assignableOwners(array $users, int $callerUserId): array
    {
        $everyone = $this->resolver->canAssignOtherOwners($callerUserId) && $this->canViewOthers($callerUserId);

        return OwnerOptionsPresenter::offered($everyone, $users, $callerUserId);
    }

    private function canViewOthers(int $callerUserId): bool
    {
        return $this->permissions->hasPermission($callerUserId, Permission::PERM_USER_VIEW_OTHERS);
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
}
