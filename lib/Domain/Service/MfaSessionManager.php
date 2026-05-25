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

namespace Poweradmin\Domain\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MfaSessionManager
 *
 * Centralizes all MFA-related session operations to provide consistency
 * across different parts of the application.
 */
class MfaSessionManager
{
    private static ?LoggerInterface $logger = null;

    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    private static function getLogger(): LoggerInterface
    {
        return self::$logger ?? new NullLogger();
    }

    /**
     * Flags a user as requiring MFA verification
     *
     * @param int $userId The user ID
     * @return void
     */
    public static function setMfaRequired(int $userId): void
    {
        $_SESSION[SessionKeys::USER_ID] = $userId;
        $_SESSION[SessionKeys::MFA_STATUS] = 'required';
        $_SESSION[SessionKeys::AUTHENTICATED] = false;
        $_SESSION[SessionKeys::MFA_REQUIRED] = true;
        $_SESSION[SessionKeys::LASTMOD] = time();

        self::getLogger()->debug('[MfaSessionManager] MFA required set for user: {user_id}', ['user_id' => $userId]);

        // Save session immediately
        session_write_close();
        session_start();
    }

    /**
     * Marks MFA as completed and sets the user as fully authenticated
     *
     * @return void
     */
    public static function setMfaVerified(): void
    {
        $_SESSION[SessionKeys::MFA_STATUS] = 'verified';
        $_SESSION[SessionKeys::AUTHENTICATED] = true;
        $_SESSION[SessionKeys::MFA_REQUIRED] = false;
        $_SESSION[SessionKeys::LASTMOD] = time();

        // Add a special token to prevent redirect loops
        $_SESSION[SessionKeys::MFA_VERIFICATION_TOKEN] = hash('sha256', time() . $_SESSION[SessionKeys::USERID] . 'verified' . random_bytes(16));

        $userId = $_SESSION[SessionKeys::USERID] ?? 0;
        self::getLogger()->debug('[MfaSessionManager] MFA verified set for user: {user_id}', ['user_id' => $userId]);

        // Save session immediately
        session_write_close();
        session_start();
    }

    /**
     * Check if MFA is required for the current user
     *
     * @return bool
     */
    public static function isMfaRequired(): bool
    {
        // Special case: if we have a verification token, MFA is not required
        if (isset($_SESSION[SessionKeys::MFA_VERIFICATION_TOKEN])) {
            // Verification token indicates MFA is already verified
            return false;
        }

        // Check our simplified status flag first
        if (isset($_SESSION[SessionKeys::MFA_STATUS]) && $_SESSION[SessionKeys::MFA_STATUS] === 'verified') {
            return false;
        }

        // If authenticated explicitly true and mfa_required explicitly false, no need for MFA
        if (
            isset($_SESSION[SessionKeys::AUTHENTICATED]) && $_SESSION[SessionKeys::AUTHENTICATED] === true &&
            isset($_SESSION[SessionKeys::MFA_REQUIRED]) && $_SESSION[SessionKeys::MFA_REQUIRED] === false
        ) {
            return false;
        }

        // In all other cases, check if our main flag is set
        return isset($_SESSION[SessionKeys::MFA_REQUIRED]) && $_SESSION[SessionKeys::MFA_REQUIRED] === true;
    }

    /**
     * Reset all MFA-related session variables
     *
     * @return void
     */
    public static function reset(): void
    {
        unset($_SESSION[SessionKeys::MFA_STATUS]);
        unset($_SESSION[SessionKeys::MFA_REQUIRED]);
        unset($_SESSION[SessionKeys::MFA_VERIFICATION_TOKEN]);

        $userId = $_SESSION[SessionKeys::USERID] ?? 0;
        self::getLogger()->debug('[MfaSessionManager] Session variables reset for user: {user_id}', ['user_id' => $userId]);

        // Save session immediately
        session_write_close();
        session_start();
    }
}
