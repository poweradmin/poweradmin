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

namespace Poweradmin\Infrastructure\Database;

use PDOException;

/**
 * Re-runs a whole transaction the database rolled back because it lost a lock race.
 *
 * Only for callers that own the transaction: a deadlock victim is rolled back as a whole,
 * so the attempt must begin and commit it again, and an outer transaction cannot be replayed.
 */
final class DeadlockRetry
{
    public const MAX_ATTEMPTS = 4;

    /** SQLSTATE values for a serialization failure (MySQL, PostgreSQL) and a PostgreSQL deadlock. */
    private const SQLSTATES = ['40001', '40P01'];

    /** MySQL driver code for a deadlock; a lock wait timeout (1205) already cost a full wait, so it is not replayed. */
    private const DRIVER_CODES = [1213];

    /**
     * @template T
     * @param callable(): T $attempt Begins, runs and commits the transaction, rolling back on failure
     * @param (callable(int): void)|null $pause Waits before the given retry number; null sleeps with jitter
     * @return T
     */
    public static function run(callable $attempt, ?callable $pause = null, int $maxAttempts = self::MAX_ATTEMPTS): mixed
    {
        for ($try = 1;; $try++) {
            try {
                return $attempt();
            } catch (\Throwable $e) {
                if ($try >= $maxAttempts || !self::isRetryable($e)) {
                    throw $e;
                }
                $pause !== null ? $pause($try) : self::sleepWithJitter($try);
            }
        }
    }

    public static function isRetryable(\Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }

        $sqlState = $e->errorInfo[0] ?? $e->getCode();
        if (is_string($sqlState) && in_array($sqlState, self::SQLSTATES, true)) {
            return true;
        }

        $driverCode = $e->errorInfo[1] ?? null;
        if (is_int($driverCode) && in_array($driverCode, self::DRIVER_CODES, true)) {
            return true;
        }

        // SQLite reports these as HY000 with the code only in the message
        return stripos($e->getMessage(), 'database is locked') !== false
            || stripos($e->getMessage(), 'database table is locked') !== false;
    }

    private static function sleepWithJitter(int $try): void
    {
        usleep(random_int(10_000, 40_000) * $try);
    }
}
