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

use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * How many zones a user or group may own, and whether a change would exceed it.
 *
 * A user counts the zones they own directly, a group the zones granted to it; group
 * grants never count toward the members. The limit binds the owner, whoever acts, and
 * a superuser owner is never limited. Lowering a limit keeps existing zones.
 */
class ZoneOwnershipLimit
{
    /** Largest value the INT columns hold on every supported database */
    public const MAX_LIMIT = 2147483647;
    public const ERR_FORBIDDEN = 'zone_limit_forbidden';
    public const ERR_INVALID = 'zone_limit_invalid';
    public const ERR_NOT_FOUND = 'zone_limit_not_found';

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserGroupRepositoryInterface $groups,
        private readonly ZoneGroupRepositoryInterface $zoneGroups,
        private readonly PermissionService $permissions,
        private readonly ConfigurationInterface $config
    ) {
    }

    /**
     * The user's limit: their own, else dns.default_max_zones_per_user. Null means unlimited.
     */
    public function userLimit(int $userId): ?int
    {
        if ($this->permissions->isAdmin($userId)) {
            return null;
        }

        return $this->users->findZoneLimit($userId) ?? $this->configuredDefault('default_max_zones_per_user');
    }

    /**
     * The group's limit: its own, else dns.default_max_zones_per_group. Null means unlimited.
     */
    public function groupLimit(int $groupId): ?int
    {
        return $this->groups->findById($groupId)?->getMaxZones() ?? $this->configuredDefault('default_max_zones_per_group');
    }

    /**
     * Owned count and limit for a page of users, in two queries; for the users list.
     *
     * @param int[] $userIds
     * @return array<int, array{owned: int, limit: ?int}> Keyed by user id
     */
    public function userUsage(array $userIds): array
    {
        $counts = $this->users->countDirectlyOwnedZones($userIds);
        $limits = $this->users->findZoneLimits($userIds);
        $default = $this->configuredDefault('default_max_zones_per_user');

        $usage = [];
        foreach ($counts as $userId => $owned) {
            $usage[$userId] = [
                'owned' => $owned,
                'limit' => $this->permissions->isAdmin($userId) ? null : ($limits[$userId] ?? $default),
            ];
        }

        return $usage;
    }

    public function userZoneCount(int $userId): int
    {
        return count($this->users->getDirectlyOwnedZoneIds($userId));
    }

    public function groupZoneCount(int $groupId): int
    {
        return $this->zoneGroups->countGrantedZones($groupId);
    }

    /**
     * Zones the user can still take on, or null when unlimited.
     */
    public function userRemaining(int $userId): ?int
    {
        $limit = $this->userLimit($userId);

        return $limit === null ? null : max(0, $limit - $this->userZoneCount($userId));
    }

    /**
     * Whether giving the user $adding more zones would exceed their limit.
     */
    public function userBreach(int $userId, int $adding = 1): ?ZoneLimitBreach
    {
        $limit = $this->userLimit($userId);
        if ($limit === null) {
            return null;
        }

        $owned = $this->userZoneCount($userId);
        if ($owned + $adding <= $limit) {
            return null;
        }

        $user = $this->users->getUserById($userId);

        return new ZoneLimitBreach(ZoneLimitBreach::SUBJECT_USER, (string)($user['username'] ?? $userId), $owned, $limit);
    }

    /**
     * Whether granting the group $adding more zones would exceed its limit.
     */
    public function groupBreach(int $groupId, int $adding = 1): ?ZoneLimitBreach
    {
        $limit = $this->groupLimit($groupId);
        if ($limit === null) {
            return null;
        }

        $owned = $this->groupZoneCount($groupId);
        if ($owned + $adding <= $limit) {
            return null;
        }

        $group = $this->groups->findById($groupId);

        return new ZoneLimitBreach(ZoneLimitBreach::SUBJECT_GROUP, $group?->getName() ?? (string)$groupId, $owned, $limit);
    }

    /**
     * The first owner a new zone would take past its limit: the user owner, then each group.
     *
     * @param list<int> $groupIds
     */
    public function newZoneBreach(?int $ownerUserId, array $groupIds): ?ZoneLimitBreach
    {
        if ($ownerUserId !== null && $ownerUserId > 0 && ($breach = $this->userBreach($ownerUserId)) !== null) {
            return $breach;
        }

        foreach (array_unique($groupIds) as $groupId) {
            if (($breach = $this->groupBreach((int)$groupId)) !== null) {
                return $breach;
            }
        }

        return null;
    }

    /**
     * Whether moving every zone of one user to another would exceed the receiver's limit.
     * Zones the receiver already owns do not count twice.
     */
    public function transferBreach(int $fromUserId, int $toUserId): ?ZoneLimitBreach
    {
        return $this->zonesBreach($toUserId, $this->users->getDirectlyOwnedZoneIds($fromUserId));
    }

    /**
     * Whether making the user an owner of these zones would exceed their limit.
     * Zones the user already owns do not count twice.
     *
     * @param array<int, int> $zoneIds Canonical zone ids
     */
    public function zonesBreach(int $userId, array $zoneIds): ?ZoneLimitBreach
    {
        if ($this->userLimit($userId) === null) {
            return null;
        }

        $adding = count(array_diff(array_unique($zoneIds), $this->users->getDirectlyOwnedZoneIds($userId)));

        return $adding === 0 ? null : $this->userBreach($userId, $adding);
    }

    /**
     * Set or clear (null) a user's own limit. Only a superuser may, so a delegated
     * administrator cannot lift the limits of the users they manage.
     *
     * @return array{success: true}|array{success: false, message: string, refusal: Refusal, code: string}
     */
    public function setUserLimit(int $actingUserId, int $userId, ?int $limit): array
    {
        if (($refusal = $this->writeRefusal($actingUserId, $limit)) !== null) {
            return $refusal;
        }
        if ($this->users->getUserById($userId) === null) {
            return ['success' => false, 'message' => 'User not found', 'refusal' => Refusal::NOT_FOUND, 'code' => self::ERR_NOT_FOUND];
        }

        $this->users->setZoneLimit($userId, $limit);

        return ['success' => true];
    }

    /**
     * Set or clear (null) a group's own limit; superusers only, as for users.
     *
     * @return array{success: true}|array{success: false, message: string, refusal: Refusal, code: string}
     */
    public function setGroupLimit(int $actingUserId, int $groupId, ?int $limit): array
    {
        if (($refusal = $this->writeRefusal($actingUserId, $limit)) !== null) {
            return $refusal;
        }
        if ($this->groups->findById($groupId) === null) {
            return ['success' => false, 'message' => 'Group not found', 'refusal' => Refusal::NOT_FOUND, 'code' => self::ERR_NOT_FOUND];
        }

        $this->groups->setZoneLimit($groupId, $limit);

        return ['success' => true];
    }

    /**
     * Whether the acting user may change limits at all.
     */
    public function maySetLimits(int $actingUserId): bool
    {
        return $this->permissions->isAdmin($actingUserId);
    }

    /**
     * @return array{success: false, message: string, refusal: Refusal, code: string}|null
     */
    private function writeRefusal(int $actingUserId, ?int $limit): ?array
    {
        if (!$this->maySetLimits($actingUserId)) {
            return ['success' => false, 'message' => 'Only superusers may change zone limits', 'refusal' => Refusal::FORBIDDEN, 'code' => self::ERR_FORBIDDEN];
        }
        if ($limit !== null && ($limit < 0 || $limit > self::MAX_LIMIT)) {
            return ['success' => false, 'message' => 'max_zones must be null or a whole number from 0 to ' . self::MAX_LIMIT, 'refusal' => Refusal::INVALID_INPUT, 'code' => self::ERR_INVALID];
        }

        return null;
    }

    private function configuredDefault(string $key): ?int
    {
        $value = $this->config->get('dns', $key);

        return is_int($value) && $value >= 0 ? $value : null;
    }
}
