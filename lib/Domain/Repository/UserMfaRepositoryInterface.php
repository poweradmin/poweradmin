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

namespace Poweradmin\Domain\Repository;

use Poweradmin\Domain\Model\UserMfa;

interface UserMfaRepositoryInterface
{
    /**
     * Find MFA settings by user ID
     *
     * @param int $userId The user ID
     * @return UserMfa|null The UserMfa object if found, null otherwise
     */
    public function findByUserId(int $userId): ?UserMfa;

    /**
     * Find MFA settings by ID
     *
     * @param int $id The MFA settings ID
     * @return UserMfa|null The UserMfa object if found, null otherwise
     */
    public function findById(int $id): ?UserMfa;

    /**
     * Save MFA settings (create or update)
     *
     * @param UserMfa $userMfa The MFA settings to save
     * @return UserMfa The saved UserMfa object with updated ID if it was created
     */
    public function save(UserMfa $userMfa): UserMfa;

    /**
     * Delete MFA settings
     *
     * @param UserMfa $userMfa The MFA settings to delete
     * @return bool True if the settings were deleted, false otherwise
     */
    public function delete(UserMfa $userMfa): bool;

    /**
     * Get all users with MFA enabled
     *
     * @return array<UserMfa> Array of UserMfa objects for users with MFA enabled
     */
    public function findAllEnabled(): array;
}
