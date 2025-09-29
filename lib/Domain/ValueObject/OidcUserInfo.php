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

namespace Poweradmin\Domain\ValueObject;

/**
 * Value object representing OIDC user information
 */
class OidcUserInfo implements UserInfoInterface
{
    private string $username;
    private string $email;
    private string $firstName;
    private string $lastName;
    private string $displayName;
    private array $groups;
    private string $providerId;
    private string $subject;
    private array $rawData;
    private ?string $avatarUrl;

    public function __construct(
        string $username,
        string $email,
        string $firstName = '',
        string $lastName = '',
        string $displayName = '',
        array $groups = [],
        string $providerId = '',
        string $subject = '',
        array $rawData = [],
        ?string $avatarUrl = null
    ) {
        $this->username = $username;
        $this->email = $email;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->displayName = $displayName ?: trim($firstName . ' ' . $lastName);
        $this->groups = $groups;
        $this->providerId = $providerId;
        $this->subject = $subject;
        $this->rawData = $rawData;
        $this->avatarUrl = $avatarUrl;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getFullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function getGroups(): array
    {
        return $this->groups;
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    public function hasGroup(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function isValid(): bool
    {
        return !empty($this->username) && !empty($this->subject);
    }
}
