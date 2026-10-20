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

namespace Poweradmin\Domain\ValueObject;

use Poweradmin\Domain\Enum\AuthMethod;

/**
 * The user the web server or an authenticating proxy has already signed in.
 * Its identity is the username itself, as with LDAP.
 */
final readonly class RemoteUserInfo implements UserInfoInterface
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        private string $username,
        private string $email = '',
        private string $displayName = '',
        private array $groups = []
    ) {
    }

    public function authMethod(): AuthMethod
    {
        return AuthMethod::REMOTE_USER;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getFullName(): string
    {
        return $this->displayName;
    }

    public function getGroups(): array
    {
        return $this->groups;
    }

    public function getProviderId(): string
    {
        return AuthMethod::REMOTE_USER->value;
    }

    public function getSubject(): string
    {
        return $this->username;
    }

    public function getRawData(): array
    {
        return [];
    }

    public function isValid(): bool
    {
        return $this->username !== '';
    }
}
