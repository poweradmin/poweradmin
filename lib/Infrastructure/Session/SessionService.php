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

use Poweradmin\Domain\Port\SessionInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;

/**
 * Stores the login message in the session and clears auth and MFA keys on logout.
 */
class SessionService
{
    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function startSession(FlashMessage $sessionEntity): void
    {
        $this->setSessionData($sessionEntity);
    }

    public function endSession(): void
    {
        // Explicitly clear MFA-related session variables
        $this->session->remove(AuthFlowSessionKeys::MFA_STATE);
        if ($this->session->has(AuthFlowSessionKeys::MFA_REQUIRED)) {
            $this->session->remove(AuthFlowSessionKeys::MFA_REQUIRED);
        }

        // Clear authentication status
        if ($this->session->has(SessionKeys::AUTHENTICATED)) {
            $this->session->remove(SessionKeys::AUTHENTICATED);
        }

        // Handle MFA tokens if present
        if ($this->session->has(AuthFlowSessionKeys::MFA_TOKEN)) {
            $this->session->remove(AuthFlowSessionKeys::MFA_TOKEN);
        }

        // Clear user data
        if ($this->session->has(SessionKeys::USERID)) {
            $this->session->remove(SessionKeys::USERID);
        }
        if ($this->session->has(SessionKeys::USERLOGIN)) {
            $this->session->remove(SessionKeys::USERLOGIN);
        }
        if ($this->session->has(SessionKeys::USERPWD)) {
            $this->session->remove(SessionKeys::USERPWD);
        }

        // Regenerate session ID and unset all variables only if session is active
        if ($this->session->isActive()) {
            $this->session->regenerateId(true);
            $this->session->clear();
        }
    }

    public function setSessionData(FlashMessage $sessionEntity): void
    {
        $this->session->set(SessionKeys::LOGIN_MESSAGE, $sessionEntity->getMessage());
        $this->session->set(SessionKeys::LOGIN_MESSAGE_TYPE, $sessionEntity->getType());
    }
}
