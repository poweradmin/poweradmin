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

use Poweradmin\Domain\Model\User;

/**
 * Read access to individual user accounts by id, name or email.
 */
interface UserLookupInterface
{
    /**
     * Get a user by ID
     *
     * @param int $userId User ID to retrieve
     * @return array|null User data if found, null otherwise
     */
    public function getUserById(int $userId): ?array;

    /**
     * Get a user by username
     *
     * @param string $username Username to search for
     * @return array|null User data if found, null otherwise
     */
    public function getUserByUsername(string $username): ?array;

    /**
     * Find a user by username
     *
     * @param string $username Username to search for
     * @return User|null User object if found, null otherwise
     */
    public function findByUsername(string $username): ?User;

    /**
     * Get a user by email
     *
     * @param string $email Email to search for
     * @return array|null User data if found, null otherwise
     */
    public function getUserByEmail(string $email): ?array;

    /**
     * Count how many users share the given email address.
     *
     * @param string $email Email to search for
     * @return int Number of matching users
     */
    public function countUsersByEmail(string $email): int;

    /**
     * Id of the active user with exactly this email address, accent-exact so a
     * look-alike address from an identity provider cannot resolve to another account.
     */
    public function findActiveUserIdByEmail(string $email): ?int;

    /**
     * The columns external provisioning compares before writing.
     *
     * @return array{fullname: ?string, email: ?string, auth_method: ?string, perm_templ: int|string, perm_templ_source: ?string}|array{} Empty when the user does not exist
     */
    public function getProvisioningProfile(int $userId): array;

    /**
     * Get a user's full name by ID
     *
     * @param int $userId User ID to look up
     * @return string|null Full name, or null if the user does not exist
     */
    public function getFullNameById(int $userId): ?string;

    /**
     * Full name of the user with exactly this username (accent-exact match), or null when there is none
     */
    public function getFullNameByUsername(string $username): ?string;
}
