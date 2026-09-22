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

use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Port\SessionInterface;

/**
 * Session-backed identity of the current user (id, username, auth method) and the
 * session storage controllers use. Domain rules read the actor through ActorInterface instead.
 */
class UserContextService
{
    // Auth methods delegated to an external IdP (shared by session auth_used and
    // users.auth_method values); everything else validates against the local database
    /** Derived from {@see AuthMethod::isExternal()}; do not list the cases again. */
    public const EXTERNAL_AUTH_METHODS = [
        AuthMethod::LDAP->value,
        AuthMethod::OIDC->value,
        AuthMethod::SAML->value,
    ];

    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function getLoggedInUsername(): ?string
    {
        return $this->session->get(SessionKeys::USERLOGIN);
    }

    public function getLoggedInUserId(): ?int
    {
        return $this->session->get(SessionKeys::USERID);
    }

    public function getDisplayName(): ?string
    {
        return $this->session->get(SessionKeys::NAME, $this->getLoggedInUsername());
    }

    public function getAuthMethod(): ?string
    {
        return $this->session->get(SessionKeys::AUTH_USED);
    }

    /**
     * The session's auth method as an enum. Unknown or absent reads as SQL.
     * getAuthMethod() stays for Twig, which compares the raw string.
     */
    public function getAuthMethodEnum(): AuthMethod
    {
        return AuthMethod::fromDb($this->getAuthMethod());
    }

    public function getUserEmail(): ?string
    {
        // Check both OAuth and regular login email session keys
        return $this->session->get(SessionKeys::USEREMAIL, $this->session->get(SessionKeys::EMAIL)) ?? null;
    }

    public function getOAuthAvatarUrl(): ?string
    {
        return $this->session->get(SessionKeys::OAUTH_AVATAR_URL);
    }

    public function isAuthenticated(): bool
    {
        $userId = $this->getLoggedInUserId();
        return $userId !== null && $userId > 0;
    }

    public function getUserLanguage(): ?string
    {
        return $this->session->get(SessionKeys::USERLANG);
    }

    public function setUserLanguage(string $language): void
    {
        $this->session->set(SessionKeys::USERLANG, $language);
    }

    public function hasSessionData(string $key): bool
    {
        return $this->session->has($key);
    }

    public function getSessionData(string $key): mixed
    {
        return $this->session->get($key);
    }

    public function setSessionData(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    public function unsetSessionData(string $key): void
    {
        $this->session->remove($key);
    }
}
