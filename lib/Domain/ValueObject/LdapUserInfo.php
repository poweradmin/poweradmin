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

/**
 * Value object representing user information read from an LDAP entry
 */
class LdapUserInfo implements UserInfoInterface
{
    public function __construct(
        private readonly string $username,
        private readonly string $email = '',
        private readonly string $displayName = '',
        private readonly array $groups = [],
        private readonly string $subject = ''
    ) {
    }

    /**
     * Build from an ldap_get_entries() entry (attribute names lowercased,
     * multi-valued attributes as ['count' => n, 0 => ...] arrays).
     */
    public static function fromLdapEntry(array $entry, string $username, string $fullnameAttribute, string $emailAttribute): self
    {
        return new self(
            $username,
            self::firstAttributeValue($entry, $emailAttribute),
            self::firstAttributeValue($entry, $fullnameAttribute),
            [],
            (string)($entry['dn'] ?? '')
        );
    }

    private static function firstAttributeValue(array $entry, string $attribute): string
    {
        return (string)($entry[strtolower($attribute)][0] ?? '');
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
        return '';
    }

    public function getLastName(): string
    {
        return '';
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
        return 'ldap';
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getRawData(): array
    {
        return [];
    }

    public function hasGroup(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }

    public function isValid(): bool
    {
        return $this->username !== '';
    }
}
