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
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Validation\Refusal;
use Throwable;

/**
 * How many zones a user or group may own, and whether a change would exceed it.
 *
 * A user counts the zones they own directly, a group the zones granted to it; group
 * grants never count toward the members. The limit binds the owner, whoever acts, and
 * a superuser owner is never limited. Lowering a limit keeps existing zones. Grants
 * decide and write under a lock on the limited owners' rows.
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
        private readonly ConfigurationInterface $config,
        private readonly ?TransactionInterface $transaction = null,
        /** @var (callable(callable(): mixed): mixed)|null Replays a whole transaction the database rolled back on a lock race */
        private readonly mixed $retry = null
    ) {
    }

    /**
     * Makes the user an owner through $write unless that takes them past their limit.
     * With $zoneId, a user who already owns that zone (say, through a concurrent request)
     * gains nothing, so the grant is never refused for it; $write should skip the insert then.
     *
     * @template T
     * @param callable(): T $write
     * @return ZoneLimitBreach|T
     */
    public function addUserOwner(int $userId, callable $write, ?int $zoneId = null): mixed
    {
        $decide = fn(): ?ZoneLimitBreach => $zoneId !== null && in_array($zoneId, $this->users->getDirectlyOwnedZoneIds($userId), true)
            ? null
            : $this->userBreach($userId);

        return $this->guarded([$userId], [], $decide, $write);
    }

    /**
     * Grants the group a zone through $write unless that takes it past its limit. With
     * $zoneId, a group that already holds the zone gains nothing and is never refused for it.
     *
     * @template T
     * @param callable(): T $write
     * @return ZoneLimitBreach|T
     */
    public function addGroupOwner(int $groupId, callable $write, ?int $zoneId = null): mixed
    {
        $decide = fn(): ?ZoneLimitBreach => $zoneId !== null && $this->zoneGroups->exists($zoneId, $groupId)
            ? null
            : $this->groupBreach($groupId);

        return $this->guarded([], [$groupId], $decide, $write);
    }

    /**
     * Moves every zone of one user to another through $write unless that takes the
     * receiver past their limit. The receiver is counted again after the move and the move
     * rolled back if it went over, since a zone granted to the sender meanwhile moves too.
     *
     * @param callable(): bool $write
     */
    public function transferZones(int $fromUserId, int $toUserId, callable $write): ZoneLimitBreach|bool
    {
        $moveAndRecount = function () use ($write, $toUserId): ZoneLimitBreach|bool {
            $moved = $write();
            return $moved ? ($this->userBreach($toUserId, 0) ?? true) : false;
        };

        return $this->guarded(
            [$toUserId],
            [],
            fn(): ?ZoneLimitBreach => $this->transferBreach($fromUserId, $toUserId),
            $moveAndRecount,
            static fn(ZoneLimitBreach|bool $result): bool => $result === true
        );
    }

    /**
     * Gives each user the listed zones through $write, all or nothing: every limited user's
     * row is locked, each one's total re-checked, and $write returning false rolls back.
     *
     * @param array<int, list<int>> $zonesByUser Canonical zone ids keyed by the new owner
     * @param callable(): bool $write
     */
    public function reassignZones(array $zonesByUser, callable $write): ZoneLimitBreach|bool
    {
        $decide = function () use ($zonesByUser): ?ZoneLimitBreach {
            foreach ($zonesByUser as $userId => $zoneIds) {
                $breach = $userId > 0 ? $this->zonesBreach($userId, $zoneIds) : null;
                if ($breach !== null) {
                    return $breach;
                }
            }
            return null;
        };

        $userIds = array_values(array_filter(array_keys($zonesByUser), static fn(int $userId): bool => $userId > 0));

        return $this->guarded($userIds, [], $decide, $write, static fn(bool $written): bool => $written);
    }

    /**
     * The breach a new zone would cause, decided under a lock on the owners' rows.
     * For a caller that has just opened the transaction its write runs in: the lock
     * comes first, so the count that follows sees every grant committed before it.
     *
     * @param list<int> $groupIds
     */
    public function lockedNewZoneBreach(?int $ownerUserId, array $groupIds): ?ZoneLimitBreach
    {
        $groupIds = array_map('intval', $groupIds);
        $this->lockRows($ownerUserId !== null && $ownerUserId > 0 ? [$ownerUserId] : [], $groupIds);

        return $this->newZoneBreach($ownerUserId, $groupIds);
    }

    /**
     * Locks these users' rows in the open transaction, in the order every grant takes them.
     * For a writer that decides itself, such as the zone sync adopting zones.
     *
     * @param list<int> $userIds
     */
    public function lockUsers(array $userIds): void
    {
        $this->lockRows($userIds, []);
    }

    /**
     * Decides and writes in one transaction holding the limited owners' rows, so two
     * concurrent grants cannot both pass. Owners without a limit take no lock.
     *
     * @template T
     * @param list<int> $userIds
     * @param list<int> $groupIds
     * @param callable $decide Returns the breach, or null when the grant fits
     * @param callable(): T $write
     * @param callable|null $keep Given the write's result; false rolls the write back
     * @return ZoneLimitBreach|T
     */
    private function guarded(array $userIds, array $groupIds, callable $decide, callable $write, ?callable $keep = null): mixed
    {
        $limited = array_filter($userIds, fn(int $id): bool => $this->userLimit($id) !== null) !== []
            || array_filter($groupIds, fn(int $id): bool => $this->groupLimit($id) !== null) !== [];
        // Unlimited owners skip the retry here: the repository write already retries on its own
        if ($limited && $this->transaction !== null && $this->retry !== null && !$this->transaction->inTransaction()) {
            // Only a transaction opened here is replayed; a joined one belongs to its caller
            return ($this->retry)(fn(): mixed => $this->guardedOnce($userIds, $groupIds, $decide, $write, $keep));
        }

        return $this->guardedOnce($userIds, $groupIds, $decide, $write, $keep);
    }

    /**
     * @template T
     * @param list<int> $userIds
     * @param list<int> $groupIds
     * @param callable(): T $write
     * @return ZoneLimitBreach|T
     */
    private function guardedOnce(array $userIds, array $groupIds, callable $decide, callable $write, ?callable $keep): mixed
    {
        // Read before the transaction: on MySQL a plain read inside it would fix the snapshot before the lock
        $userIds = array_values(array_filter($userIds, fn(int $id): bool => $this->userLimit($id) !== null));
        $groupIds = array_values(array_filter($groupIds, fn(int $id): bool => $this->groupLimit($id) !== null));
        if ($this->transaction === null || ($userIds === [] && $groupIds === [])) {
            return $decide() ?? $write();
        }

        // An outer transaction owns its own commit; this one only joins it.
        $owned = !$this->transaction->inTransaction();
        if ($owned) {
            $this->transaction->begin();
        }

        try {
            $this->lockRows($userIds, $groupIds);
            $breach = $decide();
            if ($breach !== null) {
                if ($owned) {
                    $this->transaction->rollBack();
                }
                return $breach;
            }

            $result = $write();
            if ($owned) {
                if ($keep === null || $keep($result)) {
                    $this->transaction->commit();
                } else {
                    $this->transaction->rollBack();
                }
            }
            return $result;
        } catch (Throwable $e) {
            if ($owned && $this->transaction->inTransaction()) {
                $this->transaction->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Locks in id order, users before groups, so two writers never wait on each other in a cycle.
     *
     * @param list<int> $userIds
     * @param list<int> $groupIds
     */
    private function lockRows(array $userIds, array $groupIds): void
    {
        $userIds = array_unique($userIds);
        $groupIds = array_unique($groupIds);
        sort($userIds);
        sort($groupIds);
        foreach ($userIds as $userId) {
            $this->users->lockForZoneLimit($userId);
        }
        foreach ($groupIds as $groupId) {
            $this->groups->lockForZoneLimit($groupId);
        }
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
