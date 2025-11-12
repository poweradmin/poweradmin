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
use Poweradmin\Domain\Model\UserGroupMember;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;

/**
 * Service for managing group memberships
 *
 * Handles adding/removing users to/from groups
 */
class GroupMembershipService
{
    private UserGroupMemberRepositoryInterface $memberRepository;
    private UserGroupRepositoryInterface $groupRepository;

    public function __construct(
        UserGroupMemberRepositoryInterface $memberRepository,
        UserGroupRepositoryInterface $groupRepository
    ) {
        $this->memberRepository = $memberRepository;
        $this->groupRepository = $groupRepository;
    }

    /**
     * Add a user to a group
     *
     * @param int $groupId Group ID
     * @param int $userId User ID
     * @return UserGroupMember
     * @throws InvalidArgumentException If group not found or membership already exists
     */
    public function addUserToGroup(int $groupId, int $userId): UserGroupMember
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        // Check if membership already exists
        if ($this->memberRepository->exists($groupId, $userId)) {
            throw new InvalidArgumentException('User is already a member of this group');
        }

        return $this->memberRepository->add($groupId, $userId);
    }

    /**
     * Remove a user from a group
     *
     * Permission effect: User immediately loses permissions granted by this group
     *
     * @param int $groupId Group ID
     * @param int $userId User ID
     * @return bool
     * @throws InvalidArgumentException If group not found
     */
    public function removeUserFromGroup(int $groupId, int $userId): bool
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        return $this->memberRepository->remove($groupId, $userId);
    }

    /**
     * List all members of a group
     *
     * @param int $groupId Group ID
     * @return UserGroupMember[]
     * @throws InvalidArgumentException If group not found
     */
    public function listGroupMembers(int $groupId): array
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        return $this->memberRepository->findByGroupId($groupId);
    }

    /**
     * List all groups a user belongs to
     *
     * @param int $userId User ID
     * @return UserGroupMember[]
     */
    public function listUserGroups(int $userId): array
    {
        return $this->memberRepository->findByUserId($userId);
    }

    /**
     * Add multiple users to a group
     *
     * @param int $groupId Group ID
     * @param int[] $userIds Array of user IDs
     * @return array{success: int[], failed: array<int, string>} Results of bulk operation
     */
    public function bulkAddUsers(int $groupId, array $userIds): array
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($userIds as $userId) {
            try {
                if (!$this->memberRepository->exists($groupId, $userId)) {
                    $this->memberRepository->add($groupId, $userId);
                    $results['success'][] = $userId;
                } else {
                    $results['failed'][$userId] = 'Already a member';
                }
            } catch (\Exception $e) {
                $results['failed'][$userId] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Remove multiple users from a group
     *
     * @param int $groupId Group ID
     * @param int[] $userIds Array of user IDs
     * @return array{success: int[], failed: array<int, string>} Results of bulk operation
     */
    public function bulkRemoveUsers(int $groupId, array $userIds): array
    {
        // Validate group exists
        $group = $this->groupRepository->findById($groupId);
        if (!$group) {
            throw new InvalidArgumentException('Group not found');
        }

        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($userIds as $userId) {
            try {
                if ($this->memberRepository->remove($groupId, $userId)) {
                    $results['success'][] = $userId;
                } else {
                    $results['failed'][$userId] = 'Not a member';
                }
            } catch (\Exception $e) {
                $results['failed'][$userId] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Check if a user is a member of a group
     *
     * @param int $groupId Group ID
     * @param int $userId User ID
     * @return bool
     */
    public function isUserMember(int $groupId, int $userId): bool
    {
        return $this->memberRepository->exists($groupId, $userId);
    }
}
