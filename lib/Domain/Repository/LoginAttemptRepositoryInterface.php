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

namespace Poweradmin\Domain\Repository;

/**
 * Persistence for the login_attempts counters the authentication throttle reads and writes.
 *
 * The username lookup lives here rather than on the user repository because it is the
 * throttle's own key resolution, and the throttle is built on paths that have no
 * backend-mode wiring to hand it a full user repository.
 */
interface LoginAttemptRepositoryInterface
{
    /**
     * Whether the attempt_type column exists, which it does not between a code
     * deploy and the 4.5.0 SQL update.
     */
    public function hasAttemptTypeColumn(): bool;

    /**
     * Resolve the account an attempt belongs to, or null when the username is unknown.
     */
    public function findUserIdByUsername(string $username): ?int;

    /**
     * Record one attempt. A null $attemptType omits the stage column entirely.
     */
    public function record(?int $userId, string $ipAddress, int $timestamp, bool $successful, ?string $attemptType): void;

    /**
     * Count the failures for an account since $cutoffTime. A null $attemptType counts
     * every stage, a null $ipAddress counts every source address.
     */
    public function countFailedAttempts(int $userId, int $cutoffTime, ?string $attemptType, ?string $ipAddress): int;

    /**
     * Drop the failures for an account, scoped the same way the counting is.
     */
    public function clearFailedAttempts(int $userId, ?string $attemptType, ?string $ipAddress): void;

    /**
     * Prune every attempt recorded before $cutoffTime.
     */
    public function deleteOlderThan(int $cutoffTime): void;
}
