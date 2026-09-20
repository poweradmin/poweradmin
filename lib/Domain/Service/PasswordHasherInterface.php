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

namespace Poweradmin\Domain\Service;

use InvalidArgumentException;

/**
 * Hashes and verifies user passwords; the Application layer owns the algorithm and cost settings.
 */
interface PasswordHasherInterface
{
    /**
     * Hash a password using the specified method.
     *
     * @param string $password The password to be hashed.
     * @return string The hashed password.
     * @throws InvalidArgumentException If the password encryption method is invalid.
     */
    public function hashPassword(#[\SensitiveParameter] string $password): string;

    /**
     * Verify if a password matches the hashed password.
     *
     * @param string $password The password to be verified.
     * @param string $hash The hashed password.
     * @return bool True if the password matches, false otherwise.
     * @throws InvalidArgumentException If the hash algorithm cannot be determined.
     */
    public function verifyPassword(#[\SensitiveParameter] string $password, string $hash): bool;
}
