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

use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;

/**
 * Turns a submitted list of group references into distinct group entities
 *
 * References are integer group IDs or exact group names. Callers are expected to have
 * already established that the requester may assign groups at all.
 */
class GroupReferenceResolver
{
    private UserGroupRepositoryInterface $groupRepository;

    public function __construct(UserGroupRepositoryInterface $groupRepository)
    {
        $this->groupRepository = $groupRepository;
    }

    /**
     * @param mixed $submitted Raw value supplied by the caller
     * @param int $maxEntries Largest list accepted, guarding against a query storm
     * @return array{success: bool, groups?: UserGroup[], message?: string, status?: int}
     *         On success, groups is keyed by group ID
     */
    public function resolve(mixed $submitted, int $maxEntries): array
    {
        if (!is_array($submitted) || !array_is_list($submitted)) {
            return $this->failure('groups must be an array of group IDs or names');
        }

        if (count($submitted) > $maxEntries) {
            return $this->failure(sprintf('groups accepts at most %d entries per request', $maxEntries));
        }

        $groups = [];
        foreach ($submitted as $reference) {
            if (is_int($reference)) {
                $group = $this->groupRepository->findById($reference);
            } elseif (is_string($reference)) {
                $group = $this->resolveByName($reference);
            } else {
                return $this->failure('groups entries must be integer IDs or group names');
            }

            if ($group === null) {
                return $this->failure(sprintf('Group not found: %s', $reference));
            }

            // Keyed by ID so an ID and its own name in one list yield a single membership
            $groups[(int)$group->getId()] = $group;
        }

        return ['success' => true, 'groups' => $groups];
    }

    /**
     * MySQL collates group names case- and accent-insensitively, so the exact comparison
     * is what keeps all three supported databases resolving a name the same way.
     */
    private function resolveByName(string $name): ?UserGroup
    {
        $group = $this->groupRepository->findByName($name);

        return $group !== null && $group->getName() === $name ? $group : null;
    }

    /**
     * @return array{success: false, message: string, status: int}
     */
    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message, 'status' => 400];
    }
}
