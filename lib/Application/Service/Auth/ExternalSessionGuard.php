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

use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Enum\AuthMethod;
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
     * Identifies this installation, so a session id minted by another one that shares
     * the session store cannot sign in here.
     */
    public static function instanceFingerprint(ConfigurationInterface $config): string
    {
        $parts = [
            $config->get('database', 'host', ''),
            $config->get('database', 'port', ''),
            $config->get('database', 'name', ''),
            $config->get('database', 'type', ''),
            $config->get('database', 'file', ''),
            $config->get('interface', 'base_url_prefix', ''),
        ];

        return hash_hmac('sha256', implode('|', array_map('strval', $parts)), (string)$config->get('security', 'session_key', ''));
    }

    /**
     * False when the session was not established by this installation's sign-in.
     */
    public function belongsToInstance(string $fingerprint): bool
    {
        $stored = $this->session->get(SessionKeys::SSO_INSTANCE);

        return is_string($stored) && hash_equals($fingerprint, $stored);
    }

    /**
     * False when the session's account, or the one awaiting MFA, was disabled or deleted.
     * A session without a user id holds no account and is left to the login flow.
     */
    public function accountIsActive(): bool
    {
        // Both can be set when a signed-in user starts another login that needs MFA
        $login = $this->session->get(SessionKeys::USERLOGIN);
        foreach ([SessionKeys::USERID, SessionKeys::PENDING_USERID] as $key) {
            $userId = $this->session->get($key);
            $name = is_string($login) && $login !== '' ? $login : null;
            if (is_numeric($userId) && (int)$userId > 0 && !$this->userLookup->isActiveUser((int)$userId, $name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True once an administrator moved the session's account to LDAP or web server
     * sign-in; an SSO session must not outlive that. An SQL account that also signs
     * in through SSO keeps its session.
     */
    public function accountMovedToDirectorySignIn(): bool
    {
        $login = $this->session->get(SessionKeys::USERLOGIN);
        if (!is_string($login) || $login === '') {
            return false;
        }

        $method = $this->userLookup->findAuthMethodRow($login)['auth_method'] ?? null;

        return in_array($method, [AuthMethod::LDAP->value, AuthMethod::REMOTE_USER->value], true);
    }
}
