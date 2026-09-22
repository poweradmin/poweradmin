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

namespace Poweradmin\Infrastructure\Session;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Poweradmin\Domain\Enum\MfaSessionState;
use Poweradmin\Domain\Port\SessionInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;

/**
 * Sets, reads and resets the MFA pending/verified state in the session.
 */
class MfaSessionManager
{
    private LoggerInterface $logger;

    public function __construct(private readonly SessionInterface $session, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Flags a user as requiring MFA verification
     *
     * @param int $userId The user ID
     * @return void
     */
    public function setMfaRequired(int $userId): void
    {
        // SQL auth re-runs on every request, so this is called again long after
        // the factor was accepted. Downgrading the same user's verified session
        // back to pending would redirect it to /mfa/verify forever. A different
        // user signing in on the same session must still be challenged.
        $verifiedUserId = $this->session->get(SessionKeys::USERID);
        if ($this->currentState() === MfaSessionState::VERIFIED && (int)$verifiedUserId === $userId) {
            return;
        }

        $this->session->set(AuthFlowSessionKeys::MFA_STATE, MfaSessionState::PENDING->value);
        $this->session->set(AuthFlowSessionKeys::MFA_STATUS, 'required');
        $this->session->set(SessionKeys::AUTHENTICATED, false);
        $this->session->set(AuthFlowSessionKeys::MFA_REQUIRED, true);
        $this->session->set(SessionKeys::LASTMOD, time());

        $this->logger->debug('[MfaSessionManager] MFA required set for user: {user_id}', ['user_id' => $userId]);

        // Save session immediately
        $this->session->writeClose();
        $this->session->start();
    }

    /**
     * Marks MFA as completed and sets the user as fully authenticated
     *
     * @return void
     */
    public function setMfaVerified(): void
    {
        $this->session->set(AuthFlowSessionKeys::MFA_STATE, MfaSessionState::VERIFIED->value);
        $this->session->set(AuthFlowSessionKeys::MFA_STATUS, 'verified');
        $this->session->set(SessionKeys::AUTHENTICATED, true);
        $this->session->set(AuthFlowSessionKeys::MFA_REQUIRED, false);
        $this->session->set(SessionKeys::LASTMOD, time());

        // Add a special token to prevent redirect loops
        $this->session->set(AuthFlowSessionKeys::MFA_VERIFICATION_TOKEN, hash('sha256', time() . $this->session->get(SessionKeys::USERID) . 'verified' . random_bytes(16)));

        $userId = $this->session->get(SessionKeys::USERID, 0);
        $this->logger->debug('[MfaSessionManager] MFA verified set for user: {user_id}', ['user_id' => $userId]);

        // Save session immediately
        $this->session->writeClose();
        $this->session->start();
    }

    /**
     * Check if MFA is required for the current user
     *
     * @return bool
     */
    public function isMfaRequired(): bool
    {
        return $this->currentState()->blocksAccess();
    }

    /**
     * The session's verification state.
     *
     * Sessions established before MFA_STATE existed are classified from the
     * legacy slots, which is why that reconciliation is still here. Its
     * "absent means not required" default is load-bearing: users who have no
     * second factor never get any of these keys set.
     */
    public function currentState(): MfaSessionState
    {
        $state = MfaSessionState::tryFromSession($this->session->get(AuthFlowSessionKeys::MFA_STATE));
        if ($state !== null) {
            return $state;
        }

        if ($this->session->has(AuthFlowSessionKeys::MFA_VERIFICATION_TOKEN)) {
            return MfaSessionState::VERIFIED;
        }

        if ($this->session->get(AuthFlowSessionKeys::MFA_STATUS) === 'verified') {
            return MfaSessionState::VERIFIED;
        }

        if (
            ($this->session->get(SessionKeys::AUTHENTICATED)) === true &&
            ($this->session->get(AuthFlowSessionKeys::MFA_REQUIRED)) === false
        ) {
            return MfaSessionState::NOT_REQUIRED;
        }

        return $this->session->get(AuthFlowSessionKeys::MFA_REQUIRED) === true
            ? MfaSessionState::PENDING
            : MfaSessionState::NOT_REQUIRED;
    }

    /**
     * Record that no second factor applies, so the four slots cannot disagree.
     */
    public function setMfaNotRequired(): void
    {
        $this->session->set(AuthFlowSessionKeys::MFA_STATE, MfaSessionState::NOT_REQUIRED->value);
        $this->session->set(AuthFlowSessionKeys::MFA_REQUIRED, false);
    }

    /**
     * Reset all MFA-related session variables
     *
     * @return void
     */
    public function reset(): void
    {
        $this->session->remove(AuthFlowSessionKeys::MFA_STATE);
        $this->session->remove(AuthFlowSessionKeys::MFA_STATUS);
        $this->session->remove(AuthFlowSessionKeys::MFA_REQUIRED);
        $this->session->remove(AuthFlowSessionKeys::MFA_VERIFICATION_TOKEN);

        $userId = $this->session->get(SessionKeys::USERID, 0);
        $this->logger->debug('[MfaSessionManager] Session variables reset for user: {user_id}', ['user_id' => $userId]);

        // Save session immediately
        $this->session->writeClose();
        $this->session->start();
    }
}
