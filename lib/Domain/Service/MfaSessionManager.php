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

use Random\RandomException;

/**
 * MfaSessionManager
 *
 * Centralizes all MFA-related session operations to provide consistency
 * across different parts of the application.
 */
class MfaSessionManager
{
    /**
     * Flags a user as requiring MFA verification
     *
     * @param int $userId The user ID
     * @return void
     */
    public static function setMfaRequired(int $userId): void
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['mfa_status'] = 'required';
        $_SESSION['authenticated'] = false;
        $_SESSION['mfa_required'] = true;
        $_SESSION['lastmod'] = time();

        error_log("[MfaSessionManager] MFA required set for user: $userId");

        // Save session immediately
        session_write_close();
        session_start();
    }

    /**
     * Marks MFA as completed and sets the user as fully authenticated
     *
     * @return void
     * @throws RandomException
     */
    public static function setMfaVerified(): void
    {
        $_SESSION['mfa_status'] = 'verified';
        $_SESSION['authenticated'] = true;
        $_SESSION['mfa_required'] = false;
        $_SESSION['lastmod'] = time();

        // Add a special token to prevent redirect loops
        $_SESSION['mfa_verification_token'] = hash('sha256', time() . $_SESSION['userid'] . 'verified' . random_bytes(16));

        $userId = $_SESSION['userid'] ?? 0;
        error_log("[MfaSessionManager] MFA verified set for user: $userId");

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
        if (isset($_SESSION['mfa_verification_token'])) {
            // Verification token indicates MFA is already verified
            return false;
        }

        // Check our simplified status flag first
        if (isset($_SESSION['mfa_status']) && $_SESSION['mfa_status'] === 'verified') {
            return false;
        }

        // If authenticated explicitly true and mfa_required explicitly false, no need for MFA
        if (
            isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true &&
            isset($_SESSION['mfa_required']) && $_SESSION['mfa_required'] === false
        ) {
            return false;
        }

        // In all other cases, check if our main flag is set
        return isset($_SESSION['mfa_required']) && $_SESSION['mfa_required'] === true;
    }

    /**
     * Reset all MFA-related session variables
     *
     * @return void
     */
    public static function reset(): void
    {
        unset($_SESSION['mfa_status']);
        unset($_SESSION['mfa_required']);
        unset($_SESSION['mfa_verification_token']);

        $userId = $_SESSION['userid'] ?? 0;
        error_log("[MfaSessionManager] Session variables reset for user: $userId");

        // Save session immediately
        session_write_close();
        session_start();
    }
}
