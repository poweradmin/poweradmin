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

/**
 * Records login attempts and reports lockouts; the Application layer owns the storage and thresholds.
 * Stage identifiers are the {@see LoginAttemptStage} values.
 */
interface LoginThrottleInterface
{
    /**
     * @param string $attemptType Lockout stage identifier; defaults to "password"
     *                            so existing callers (SQL/LDAP/DDNS) are unchanged.
     *                            Pass STAGE_MFA from the MFA verify path to keep
     *                            second-factor failures from polluting the
     *                            first-factor counter.
     * @param int|null $userId Account the attempt belongs to. Pass it whenever the
     *                         caller already knows it, rather than relying on the
     *                         username to resolve: the MFA stage verifies against
     *                         the pending user id, and keying the counter on a
     *                         separately held session name would let the two drift.
     */
    public function recordAttempt(string $username, string $ipAddress, bool $successful, string $attemptType = 'password', ?int $userId = null): void;

    public function isAccountLocked(string $username, string $ipAddress, string $attemptType = 'password', ?int $userId = null): bool;
}
