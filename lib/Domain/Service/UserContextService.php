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

class UserContextService
{
    // Request-scoped fallback for stateless API requests. Web UI requests use
    // session-backed values directly; API requests authenticate via API key /
    // Basic auth and have no $_SESSION user, so PublicApiController seeds these
    // after authentication succeeds. Both fields are cleared when the request
    // process ends; tests should reset them in tearDown.
    private static ?int $apiUserId = null;
    private static ?string $apiUsername = null;

    public static function setApiUserContext(int $userId, ?string $username): void
    {
        self::$apiUserId = $userId > 0 ? $userId : null;
        self::$apiUsername = ($username !== null && $username !== '') ? $username : null;
    }

    public static function clearApiUserContext(): void
    {
        self::$apiUserId = null;
        self::$apiUsername = null;
    }

    public function getLoggedInUsername(): ?string
    {
        return $_SESSION['userlogin'] ?? self::$apiUsername;
    }

    public function getLoggedInUserId(): ?int
    {
        return $_SESSION['userid'] ?? self::$apiUserId;
    }

    public function getDisplayName(): ?string
    {
        return $_SESSION['name'] ?? $this->getLoggedInUsername();
    }

    public function getAuthMethod(): ?string
    {
        return $_SESSION['auth_used'] ?? null;
    }

    public function getUserEmail(): ?string
    {
        // Check both OAuth and regular login email session keys
        return $_SESSION['useremail'] ?? $_SESSION['email'] ?? null;
    }

    public function getOAuthAvatarUrl(): ?string
    {
        return $_SESSION['oauth_avatar_url'] ?? null;
    }

    public function isAuthenticated(): bool
    {
        $userId = $this->getLoggedInUserId();
        return $userId !== null && $userId > 0;
    }

    public function getUserLanguage(): ?string
    {
        return $_SESSION['userlang'] ?? null;
    }

    public function setUserLanguage(string $language): void
    {
        $_SESSION['userlang'] = $language;
    }

    public function hasSessionData(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function getSessionData(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function setSessionData(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function unsetSessionData(string $key): void
    {
        unset($_SESSION[$key]);
    }
}
