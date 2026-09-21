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
 * Persistence for username recovery attempts and their rate-limit counters.
 */
interface UsernameRecoveryRepositoryInterface
{
    /**
     * Record a username recovery request.
     *
     * @param array $data Request data containing email and ip_address
     * @return bool True if the record was created successfully
     */
    public function create(array $data): bool;

    /**
     * Count recent recovery attempts for an email address.
     *
     * @param int $seconds Time window in seconds to check within
     */
    public function countRecentAttempts(string $email, int $seconds): int;

    /**
     * Count recent recovery attempts by IP address.
     *
     * @param int $seconds Time window in seconds to check within
     */
    public function countRecentAttemptsByIp(string $ip, int $seconds): int;

    /**
     * Delete records older than the given number of days.
     *
     * @return int Number of deleted records
     */
    public function deleteOlderThan(int $days = 30): int;
}
