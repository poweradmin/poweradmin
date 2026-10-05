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

namespace Poweradmin\Application\Service\Auth;

use Poweradmin\Domain\Port\SessionInterface;
use Poweradmin\Domain\Repository\AuthUserLookupInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;

/**
 * Re-checks the account behind a session an identity provider established. SQL and
 * LDAP sessions re-verify the account on every request; OIDC and SAML ones would not.
 */
final class ExternalSessionGuard
{
    public function __construct(
        private readonly AuthUserLookupInterface $userLookup,
        private readonly SessionInterface $session
    ) {
    }

    /**
     * False when the session's account, or the one awaiting MFA, was disabled or deleted.
     * A session without a user id holds no account and is left to the login flow.
     */
    public function accountIsActive(): bool
    {
        // Both can be set when a signed-in user starts another login that needs MFA
        foreach ([SessionKeys::USERID, SessionKeys::PENDING_USERID] as $key) {
            $userId = $this->session->get($key);
            if (is_numeric($userId) && (int)$userId > 0 && !$this->userLookup->isActiveUser((int)$userId)) {
                return false;
            }
        }

        return true;
    }
}
