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

namespace Poweradmin\Domain\Service\Zone;

use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;

/**
 * Resolves the user-owner and group-owner assignment for a new zone, applying
 * the active zone_ownership_mode and permission rules. The API hands in its
 * JSON body; the web forms hand in the owner and groups they already parsed.
 */
class ZoneCreateOwnershipResolver
{
    private ZoneOwnershipModeService $mode;
    private PermissionService $permissions;
    private UserGroupLookupInterface $groups;
    private UserLookupInterface $users;

    public function __construct(
        ZoneOwnershipModeService $mode,
        PermissionService $permissions,
        UserGroupLookupInterface $groups,
        UserLookupInterface $users
    ) {
        $this->mode = $mode;
        $this->permissions = $permissions;
        $this->groups = $groups;
        $this->users = $users;
    }

    /**
     * Why a user cannot pick any owner for a new zone in groups_only mode:
     * no group exists at all, or the user belongs to none. Null when they can.
     */
    public function ownerOptionsBlocker(int $callerUserId): ?string
    {
        if ($this->mode->isUserOwnerAllowed()) {
            return null;
        }
        if ($this->permissions->isAdmin($callerUserId)) {
            return $this->groups->findAll() === [] ? ZoneOwnershipResolution::NO_GROUPS_EXIST : null;
        }

        return $this->groups->getGroupIdsForUser($callerUserId) === [] ? ZoneOwnershipResolution::NOT_IN_ANY_GROUP : null;
    }

    /**
     * @param array<string, mixed> $input  Decoded JSON body.
     * @param int                  $callerUserId  Authenticated caller.
     */
    public function resolve(array $input, int $callerUserId): ZoneOwnershipResolution
    {
        $ownerSupplied = array_key_exists('owner_user_id', $input);
        $groupIdsSupplied = array_key_exists('group_ids', $input);

        $groupIds = [];
        if ($groupIdsSupplied) {
            if (!is_array($input['group_ids'])) {
                return ZoneOwnershipResolution::error('group_ids must be an array of integers', 400, ZoneOwnershipResolution::INVALID_INPUT);
            }
            foreach ($input['group_ids'] as $candidate) {
                if (!is_int($candidate) && !(is_string($candidate) && ctype_digit($candidate))) {
                    return ZoneOwnershipResolution::error('group_ids must be an array of integers', 400, ZoneOwnershipResolution::INVALID_INPUT);
                }
                $groupIds[] = (int)$candidate;
            }
        }

        if (!$this->mode->isUserOwnerAllowed() && $ownerSupplied && $input['owner_user_id'] !== null) {
            return ZoneOwnershipResolution::error(
                'User-owner assignment is disabled by the current zone ownership mode (groups_only). Omit owner_user_id or set it to null.',
                400,
                ZoneOwnershipResolution::USER_OWNER_DISABLED
            );
        }
        if (!$this->mode->isGroupOwnerAllowed() && !empty($groupIds)) {
            return ZoneOwnershipResolution::error(
                'Group-owner assignment is disabled by the current zone ownership mode (users_only).',
                400,
                ZoneOwnershipResolution::GROUP_OWNER_DISABLED
            );
        }

        if (!$this->mode->isUserOwnerAllowed()) {
            $owner = null;
        } else {
            $rawOwner = $input['owner_user_id'] ?? null;
            if ($ownerSupplied && $rawOwner === null) {
                // Explicit null opts out of the user-owner default; required to
                // create a group-only zone via API in modes that allow it.
                $owner = null;
            } elseif ($ownerSupplied) {
                if (!is_int($rawOwner) && !(is_string($rawOwner) && ctype_digit($rawOwner))) {
                    return ZoneOwnershipResolution::error('owner_user_id must be a numeric ID', 400, ZoneOwnershipResolution::INVALID_INPUT);
                }
                $parsed = (int)$rawOwner;
                // Treat 0/negative as "no user owner"; matches how zones.owner=0
                // is read everywhere else and prevents orphaned-zone creation.
                $owner = $parsed > 0 ? $parsed : null;
            } else {
                // Backward-compatible default: omitted owner_user_id keeps the
                // caller as user owner even when group_ids is supplied.
                $owner = $callerUserId;
            }
        }

        return $this->resolveOwnership($owner, $groupIds, $callerUserId);
    }

    /**
     * Whether the caller may give a new zone to somebody else; the owner pickers
     * offer other users only when this holds.
     */
    public function canAssignOtherOwners(int $callerUserId): bool
    {
        return $this->permissions->hasPermission($callerUserId, Permission::PERM_ZONE_CONTENT_EDIT_OTHERS);
    }

    /**
     * The rules shared by the API and the web forms: the groups must exist, a
     * zone needs at least one owner, giving it to another user needs
     * zone_content_edit_others and that user must exist, and non-admins may
     * only pick their own groups.
     *
     * @param list<int> $groupIds
     */
    public function resolveOwnership(?int $owner, array $groupIds, int $callerUserId): ZoneOwnershipResolution
    {
        $groupIds = array_values(array_unique($groupIds));

        if (!empty($groupIds)) {
            $existing = $this->groups->findExistingIds($groupIds);
            $missing = array_values(array_diff($groupIds, $existing));
            if (!empty($missing)) {
                return ZoneOwnershipResolution::error(
                    'Unknown group ID(s): ' . implode(',', $missing),
                    404,
                    ZoneOwnershipResolution::UNKNOWN_GROUPS,
                    $missing
                );
            }
        }

        if ($owner === null && empty($groupIds)) {
            return ZoneOwnershipResolution::error(
                'At least one of owner_user_id or group_ids must be provided',
                400,
                ZoneOwnershipResolution::NO_OWNER
            );
        }

        if ($owner !== null && $owner !== $callerUserId) {
            if (!$this->canAssignOtherOwners($callerUserId)) {
                return ZoneOwnershipResolution::error(
                    'You do not have permission to create zones for other users',
                    403,
                    ZoneOwnershipResolution::OTHER_OWNER_FORBIDDEN
                );
            }
            // zones.owner has no foreign key, so an unknown id would leave a zone nobody owns.
            if ($this->users->getUserById($owner) === null) {
                return ZoneOwnershipResolution::error(
                    'Unknown user ID: ' . $owner,
                    404,
                    ZoneOwnershipResolution::UNKNOWN_OWNER,
                    [$owner]
                );
            }
        }

        if (!empty($groupIds) && !$this->permissions->isAdmin($callerUserId)) {
            $allowed = $this->groups->getGroupIdsForUser($callerUserId);
            $disallowed = array_values(array_diff($groupIds, $allowed));
            if (!empty($disallowed)) {
                return ZoneOwnershipResolution::error(
                    'You can only assign groups you are a member of (disallowed: ' . implode(',', $disallowed) . ')',
                    403,
                    ZoneOwnershipResolution::GROUPS_NOT_MEMBER,
                    $disallowed
                );
            }
        }

        return ZoneOwnershipResolution::success($owner, $groupIds);
    }
}
