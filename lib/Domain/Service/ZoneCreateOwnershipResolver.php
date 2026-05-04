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
 * Resolves the user-owner and group-owner assignment for an API zone-create
 * request, applying the active zone_ownership_mode and permission rules.
 */
class ZoneCreateOwnershipResolver
{
    private ZoneOwnershipModeService $mode;
    private ApiPermissionService $permissions;

    public function __construct(ZoneOwnershipModeService $mode, ApiPermissionService $permissions)
    {
        $this->mode = $mode;
        $this->permissions = $permissions;
    }

    /**
     * @param array<string, mixed> $input  Decoded JSON body.
     * @param int                  $callerUserId  Authenticated caller.
     *
     * @return array{owner: int|null, group_ids: array<int, int>}|array{error: string, status: int}
     *         On success, the resolved owner (nullable) and unique group ids.
     *         On failure, an error message and HTTP status code (400 or 403).
     */
    public function resolve(array $input, int $callerUserId): array
    {
        $ownerSupplied = array_key_exists('owner_user_id', $input);
        $groupIdsSupplied = array_key_exists('group_ids', $input);

        $groupIds = [];
        if ($groupIdsSupplied) {
            if (!is_array($input['group_ids'])) {
                return ['error' => 'group_ids must be an array of integers', 'status' => 400];
            }
            foreach ($input['group_ids'] as $candidate) {
                if (!is_int($candidate) && !(is_string($candidate) && ctype_digit($candidate))) {
                    return ['error' => 'group_ids must be an array of integers', 'status' => 400];
                }
                $groupIds[] = (int)$candidate;
            }
            $groupIds = array_values(array_unique($groupIds));

            if (!empty($groupIds)) {
                $existing = $this->permissions->getExistingGroupIds($groupIds);
                $missing = array_values(array_diff($groupIds, $existing));
                if (!empty($missing)) {
                    return [
                        'error' => 'Unknown group ID(s): ' . implode(',', $missing),
                        'status' => 404,
                    ];
                }
            }
        }

        if (!$this->mode->isUserOwnerAllowed() && $ownerSupplied && $input['owner_user_id'] !== null) {
            return [
                'error' => 'User-owner assignment is disabled by the current zone ownership mode (groups_only). Omit owner_user_id or set it to null.',
                'status' => 400,
            ];
        }
        if (!$this->mode->isGroupOwnerAllowed() && !empty($groupIds)) {
            return [
                'error' => 'Group-owner assignment is disabled by the current zone ownership mode (users_only).',
                'status' => 400,
            ];
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
                    return ['error' => 'owner_user_id must be a numeric ID', 'status' => 400];
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

        if ($owner === null && empty($groupIds)) {
            return [
                'error' => 'At least one of owner_user_id or group_ids must be provided',
                'status' => 400,
            ];
        }

        if ($owner !== null && $owner !== $callerUserId) {
            if (
                !$this->permissions->userHasPermission($callerUserId, 'user_is_ueberuser') &&
                !$this->permissions->userHasPermission($callerUserId, 'zone_content_edit_others')
            ) {
                return [
                    'error' => 'You do not have permission to create zones for other users',
                    'status' => 403,
                ];
            }
        }

        if (!empty($groupIds) && !$this->permissions->userHasPermission($callerUserId, 'user_is_ueberuser')) {
            $allowed = $this->permissions->getUserGroupIds($callerUserId);
            $disallowed = array_values(array_diff($groupIds, $allowed));
            if (!empty($disallowed)) {
                return [
                    'error' => 'You can only assign groups you are a member of (disallowed: ' . implode(',', $disallowed) . ')',
                    'status' => 403,
                ];
            }
        }

        return ['owner' => $owner, 'group_ids' => $groupIds];
    }
}
