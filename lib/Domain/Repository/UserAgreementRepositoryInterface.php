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

interface UserAgreementRepositoryInterface
{
    /**
     * Check if user has accepted the specified agreement version
     *
     * @param int $userId
     * @param string $version
     * @return bool
     */
    public function hasUserAcceptedAgreement(int $userId, string $version): bool;

    /**
     * Record user agreement acceptance
     *
     * @param int $userId
     * @param string $version
     * @param string $ipAddress
     * @param string $userAgent
     * @return bool
     */
    public function recordAcceptance(
        int $userId,
        string $version,
        string $ipAddress,
        string $userAgent
    ): bool;

    /**
     * Get all agreements for a specific user
     *
     * @param int $userId
     * @return array
     */
    public function getUserAgreements(int $userId): array;

    /**
     * Get all agreement records for administrative purposes
     *
     * @return array
     */
    public function getAllAgreements(): array;
}
