<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

use InvalidArgumentException;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;

/**
 * Service for managing user groups
 *
 * Handles group CRUD operations, visibility filtering, and validation
 */
class GroupService
{
    private UserGroupRepositoryInterface $groupRepository;

    public function __construct(UserGroupRepositoryInterface $groupRepository)
    {
        $this->groupRepository = $groupRepository;
    }

    /**
     * List groups with visibility filtering
     *
     * @param int $userId Current user ID
     * @param bool $isAdmin Whether the user is an admin (überuser)
     * @return UserGroup[]
     */
    public function listGroups(int $userId, bool $isAdmin): array
    {
        if ($isAdmin) {
            return $this->groupRepository->findAll();
        }

        // Normal users only see groups they belong to
        return $this->groupRepository->findByUserId($userId);
    }

    /**
     * Get a group by ID with visibility check
     *
     * @param int $groupId Group ID
     * @param int $userId Current user ID
     * @param bool $isAdmin Whether the user is an admin
     * @return UserGroup|null
     * @throws InvalidArgumentException If user doesn't have permission to view the group
     */
    public function getGroupById(int $groupId, int $userId, bool $isAdmin): ?UserGroup
    {
        $group = $this->groupRepository->findById($groupId);

        if (!$group) {
            return null;
        }

        // Admins can view any group
        if ($isAdmin) {
            return $group;
        }

        // Non-admins can only view groups they belong to
        if (!$this->canUserViewGroup($groupId, $userId)) {
            throw new InvalidArgumentException('You do not have permission to view this group');
        }

        return $group;
    }

    /**
     * Check if a user can view a group
     *
     * @param int $groupId Group ID
     * @param int $userId User ID
     * @return bool
     */
    public function canUserViewGroup(int $groupId, int $userId): bool
    {
        $userGroups = $this->groupRepository->findByUserId($userId);

        foreach ($userGroups as $group) {
            if ($group->getId() === $groupId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a new group
     *
     * @param string $name Group name (must be unique)
     * @param int $permTemplId Permission template ID
     * @param string|null $description Optional description
     * @param int|null $createdBy User ID who created the group
     * @return UserGroup
     * @throws InvalidArgumentException If name is empty or already exists
     */
    public function createGroup(
        string $name,
        int $permTemplId,
        ?string $description = null,
        ?int $createdBy = null
    ): UserGroup {
        // Validate name
        $name = trim($name);
        if (empty($name)) {
            throw new InvalidArgumentException('Group name cannot be empty');
        }

        // Check for duplicate name
        $existing = $this->groupRepository->findByName($name);
        if ($existing) {
            throw new InvalidArgumentException('A group with this name already exists');
        }

        $group = UserGroup::create($name, $permTemplId, $description, $createdBy);
        return $this->groupRepository->save($group);
    }

    /**
     * Update an existing group
     *
     * @param int $groupId Group ID
     * @param string|null $name New name (if provided)
     * @param string|null $description New description (if provided)
     * @param int|null $permTemplId New permission template ID (if provided)
     * @return UserGroup
     * @throws InvalidArgumentException If group not found or name already exists
     */
    public function updateGroup(
        int $groupId,
        ?string $name = null,
        ?string $description = null,
        ?int $permTemplId = null
    ): UserGroup {
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        // Validate new name if provided
        if ($name !== null) {
            $name = trim($name);
            if (empty($name)) {
                throw new InvalidArgumentException('Group name cannot be empty');
            }

            // Check for duplicate name (excluding current group)
            $existing = $this->groupRepository->findByName($name);
            if ($existing && $existing->getId() !== $groupId) {
                throw new InvalidArgumentException('A group with this name already exists');
            }
        }

        $updatedGroup = $group->update($name, $description, $permTemplId);
        return $this->groupRepository->save($updatedGroup);
    }

    /**
     * Delete a group
     *
     * Cascade deletes will remove all memberships and zone associations
     *
     * @param int $groupId Group ID
     * @return bool
     * @throws InvalidArgumentException If group not found
     */
    public function deleteGroup(int $groupId): bool
    {
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        return $this->groupRepository->delete($groupId);
    }

    /**
     * Get group details including member and zone counts
     *
     * @param int $groupId Group ID
     * @return array{group: UserGroup, memberCount: int, zoneCount: int}
     * @throws InvalidArgumentException If group not found
     */
    public function getGroupDetails(int $groupId): array
    {
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        return [
            'group' => $group,
            'memberCount' => $this->groupRepository->countMembers($groupId),
            'zoneCount' => $this->groupRepository->countZones($groupId)
        ];
    }

    /**
     * Check if a group name is available
     *
     * @param string $name Group name to check
     * @param int|null $excludeGroupId Group ID to exclude from check (for updates)
     * @return bool
     */
    public function isGroupNameAvailable(string $name, ?int $excludeGroupId = null): bool
    {
        $existing = $this->groupRepository->findByName($name);

        if (!$existing) {
            return true;
        }

        // If excluding a group ID (for updates), check if it's the same group
        if ($excludeGroupId !== null && $existing->getId() === $excludeGroupId) {
            return true;
        }

        return false;
    }
}
