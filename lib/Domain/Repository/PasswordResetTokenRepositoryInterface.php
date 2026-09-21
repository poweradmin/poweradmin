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
 * Persistence for password reset tokens and their rate-limit counters.
 */
interface PasswordResetTokenRepositoryInterface
{
    /**
     * Store a reset token. The raw token is supplied; the implementation stores
     * its hash and findByToken() expects that same hash.
     *
     * @param array $data Token row: email, token, expires_at, ip_address
     */
    public function create(array $data): bool;

    /**
     * Find an unused, unexpired token by its hashed value.
     */
    public function findByToken(string $token): ?array;

    /**
     * Mark a token as used.
     */
    public function markAsUsed(int $tokenId): bool;

    /**
     * Count recent attempts for an email address.
     */
    public function countRecentAttempts(string $email, int $seconds): int;

    /**
     * Count recent attempts by IP address.
     */
    public function countRecentAttemptsByIp(string $ip, int $seconds): int;

    /**
     * Delete expired tokens and used tokens past their retention window.
     *
     * @return int Number of deleted rows
     */
    public function deleteExpired(): int;

    /**
     * Delete a specific token by ID.
     */
    public function deleteById(int $tokenId): bool;
}
