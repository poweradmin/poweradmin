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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Enum\DnsBackendKind;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneLimitBreach;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use Poweradmin\Infrastructure\Database\PdoTransaction;

/**
 * Finds the Poweradmin user a PowerDNS account value names, for zones that arrive
 * without an owner (an autoprimary sets the account from its own configuration).
 */
final class AccountOwnerLookup
{
    /** @var array<string, int|null> A first sync can bring thousands of zones naming a few accounts */
    private array $resolved = [];

    /** @var array<int, int|null> Zones each user can still adopt in this run; null = unlimited */
    private array $remaining = [];

    private int $heldBack = 0;

    /**
     * @param ZoneOwnershipLimit|null $limits Zone limits adoption respects; null adopts without limit
     */
    public function __construct(private readonly PDO $db, private readonly ?ZoneOwnershipLimit $limits = null)
    {
    }

    /**
     * A lookup when dns.adopt_zone_owner_from_account is on, null otherwise.
     */
    public static function forConfig(PDO $db, ConfigurationInterface $config): ?self
    {
        if (!$config->get('dns', 'adopt_zone_owner_from_account', false)) {
            return null;
        }

        $isApiBackend = DnsBackendKind::fromConfig($config)->isApi();
        $users = new DbUserRepository($db, $config, $isApiBackend);
        $limits = new ZoneOwnershipLimit(
            $users,
            new DbUserGroupRepository($db),
            new DbZoneGroupRepository($db, $config, $isApiBackend),
            new PermissionService($users),
            $config,
            new PdoTransaction($db)
        );

        return new self($db, $limits);
    }

    /**
     * The users these accounts name who have a zone limit, for lockAdopters() once the
     * caller's transaction is open. Resets the per-run counts. Read before the transaction,
     * so on MySQL the lock is taken before the snapshot the counts read from.
     *
     * @param list<string> $accounts
     * @return list<int>
     */
    public function limitedAdopters(array $accounts): array
    {
        $this->remaining = [];
        $this->heldBack = 0;
        if ($this->limits === null) {
            return [];
        }

        $userIds = [];
        foreach ($accounts as $account) {
            $userId = $this->userIdFor($account);
            if ($userId !== null && $this->limits->userLimit($userId) !== null) {
                $userIds[$userId] = $userId;
            }
        }

        return array_values($userIds);
    }

    /**
     * Locks the adopting users' rows in the open transaction, so a grant to the same user
     * waits for the adoption and counts what it added.
     *
     * @param list<int> $userIds From limitedAdopters()
     */
    public function lockAdopters(array $userIds): void
    {
        $this->limits?->lockUsers($userIds);
    }

    /**
     * Gives one zone to the user through $write, deciding under the user's lock like any
     * other grant. Null when the user is at the limit and nothing was written.
     *
     * @param callable(): bool $write
     */
    public function assignWithinLimit(int $userId, callable $write, ?int $zoneId = null): ?bool
    {
        if ($this->limits === null) {
            return $write();
        }

        $assigned = $this->limits->addUserOwner($userId, $write, $zoneId);
        if ($assigned instanceof ZoneLimitBreach) {
            $this->heldBack++;
            return null;
        }

        return $assigned;
    }

    /**
     * The user a zone with this account should go to: userIdFor(), unless that user
     * has no zones left under their limit, in which case the zone stays ownerless.
     * Each call takes one zone from the user's remaining count.
     */
    public function adopterFor(string $account): ?int
    {
        $userId = $this->userIdFor($account);
        if ($userId === null || $this->limits === null) {
            return $userId;
        }

        // Counted once per run, then decremented, so a large first sync costs one count per user
        if (!array_key_exists($userId, $this->remaining)) {
            $this->remaining[$userId] = $this->limits->userRemaining($userId);
        }
        $remaining = $this->remaining[$userId];
        if ($remaining === null) {
            return $userId;
        }
        if ($remaining <= 0) {
            $this->heldBack++;
            return null;
        }
        $this->remaining[$userId] = $remaining - 1;

        return $userId;
    }

    /**
     * Zones adopterFor() left ownerless because their user was at the zone limit.
     */
    public function heldBack(): int
    {
        return $this->heldBack;
    }

    /**
     * The id of the user whose username is exactly $account, or null when none is.
     */
    public function userIdFor(string $account): ?int
    {
        $account = trim($account);
        if ($account === '') {
            return null;
        }
        if (array_key_exists($account, $this->resolved)) {
            return $this->resolved[$account];
        }

        $this->resolved[$account] = null;
        $stmt = $this->db->prepare('SELECT id, username FROM users WHERE username = :username');
        $stmt->execute([':username' => $account]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // A case-insensitive collation also returns "Admin" for "admin"; only the exact name counts
            if ($row['username'] === $account) {
                $this->resolved[$account] = (int)$row['id'];
                break;
            }
        }

        return $this->resolved[$account];
    }
}
