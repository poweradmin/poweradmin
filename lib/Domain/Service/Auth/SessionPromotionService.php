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

namespace Poweradmin\Domain\Service\Auth;

/**
 * Promotes the half-authenticated pending session into a fully authenticated one.
 *
 * While MFA verification is in progress, the user's identity is stored under
 * pending_* session keys so an unverified session cannot be used to reach the
 * API or the web UI as a logged-in user. After the second factor has been
 * verified successfully, this service moves each pending value to its real
 * session key and removes the pending copy. Keys that were never set stay
 * absent - nothing is written for them.
 */
final class SessionPromotionService
{
    private UserContextService $userContextService;

    public function __construct(UserContextService $userContextService)
    {
        $this->userContextService = $userContextService;
    }

    /**
     * Promote pending session variables to actual ones now that MFA is verified.
     */
    public function promotePendingSession(): void
    {
        if ($this->userContextService->hasSessionData(SessionKeys::PENDING_USERID)) {
            $this->userContextService->setSessionData(SessionKeys::USERID, $this->userContextService->getSessionData(SessionKeys::PENDING_USERID));
            $this->userContextService->unsetSessionData(SessionKeys::PENDING_USERID);
        }
        if ($this->userContextService->hasSessionData(SessionKeys::PENDING_NAME)) {
            $this->userContextService->setSessionData(SessionKeys::NAME, $this->userContextService->getSessionData(SessionKeys::PENDING_NAME));
            $this->userContextService->unsetSessionData(SessionKeys::PENDING_NAME);
        }
        if ($this->userContextService->hasSessionData(SessionKeys::PENDING_EMAIL)) {
            $this->userContextService->setSessionData(SessionKeys::EMAIL, $this->userContextService->getSessionData(SessionKeys::PENDING_EMAIL));
            $this->userContextService->unsetSessionData(SessionKeys::PENDING_EMAIL);
        }
        if ($this->userContextService->hasSessionData(SessionKeys::PENDING_AUTH_USED)) {
            $this->userContextService->setSessionData(SessionKeys::AUTH_USED, $this->userContextService->getSessionData(SessionKeys::PENDING_AUTH_USED));
            $this->userContextService->unsetSessionData(SessionKeys::PENDING_AUTH_USED);
        }
        if ($this->userContextService->hasSessionData(SessionKeys::PENDING_AUTH_METHOD_USED)) {
            $this->userContextService->setSessionData(SessionKeys::AUTH_METHOD_USED, $this->userContextService->getSessionData(SessionKeys::PENDING_AUTH_METHOD_USED));
            $this->userContextService->unsetSessionData(SessionKeys::PENDING_AUTH_METHOD_USED);
        }

        // Promote OIDC-specific pending session variables
        if ($this->userContextService->hasSessionData(SessionKeys::PENDING_OIDC_PROVIDER)) {
            $this->userContextService->setSessionData(SessionKeys::OIDC_PROVIDER, $this->userContextService->getSessionData(SessionKeys::PENDING_OIDC_PROVIDER));
            $this->userContextService->unsetSessionData(SessionKeys::PENDING_OIDC_PROVIDER);
            $this->userContextService->setSessionData(SessionKeys::OIDC_AUTHENTICATED, true);
        }
        if ($this->userContextService->hasSessionData(SessionKeys::PENDING_OIDC_ID_TOKEN)) {
            $this->userContextService->setSessionData(SessionKeys::OIDC_ID_TOKEN, $this->userContextService->getSessionData(SessionKeys::PENDING_OIDC_ID_TOKEN));
            $this->userContextService->unsetSessionData(SessionKeys::PENDING_OIDC_ID_TOKEN);
        }
        if ($this->userContextService->hasSessionData(SessionKeys::PENDING_OAUTH_AVATAR_URL)) {
            $this->userContextService->setSessionData(SessionKeys::OAUTH_AVATAR_URL, $this->userContextService->getSessionData(SessionKeys::PENDING_OAUTH_AVATAR_URL));
            $this->userContextService->unsetSessionData(SessionKeys::PENDING_OAUTH_AVATAR_URL);
        }

        // Promote SAML-specific pending session variables
        if ($this->userContextService->hasSessionData(SessionKeys::PENDING_SAML_PROVIDER)) {
            $this->userContextService->setSessionData(SessionKeys::SAML_PROVIDER, $this->userContextService->getSessionData(SessionKeys::PENDING_SAML_PROVIDER));
            $this->userContextService->unsetSessionData(SessionKeys::PENDING_SAML_PROVIDER);
            $this->userContextService->setSessionData(SessionKeys::SAML_AUTHENTICATED, true);
        }
        if ($this->userContextService->hasSessionData(SessionKeys::PENDING_SAML_NAME_ID)) {
            $this->userContextService->setSessionData(SessionKeys::SAML_NAME_ID, $this->userContextService->getSessionData(SessionKeys::PENDING_SAML_NAME_ID));
            $this->userContextService->unsetSessionData(SessionKeys::PENDING_SAML_NAME_ID);
        }
        if ($this->userContextService->hasSessionData(SessionKeys::PENDING_SAML_SESSION_INDEX)) {
            $this->userContextService->setSessionData(SessionKeys::SAML_SESSION_INDEX, $this->userContextService->getSessionData(SessionKeys::PENDING_SAML_SESSION_INDEX));
            $this->userContextService->unsetSessionData(SessionKeys::PENDING_SAML_SESSION_INDEX);
        }
    }
}
