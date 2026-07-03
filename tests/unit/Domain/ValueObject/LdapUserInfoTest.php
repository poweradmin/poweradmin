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

namespace Poweradmin\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\ValueObject\LdapUserInfo;

class LdapUserInfoTest extends TestCase
{
    /** Entry shaped like ldap_get_entries() output: lowercased keys, count markers. */
    private const ENTRY = [
        'count' => 3,
        'dn' => 'uid=jdoe,ou=users,dc=example,dc=com',
        'uid' => ['count' => 1, 0 => 'jdoe'],
        'displayname' => ['count' => 1, 0 => 'John Doe'],
        'mail' => ['count' => 2, 0 => 'jdoe@example.com', 1 => 'john@example.com'],
    ];

    public function testFromLdapEntryReadsAttributesCaseInsensitively(): void
    {
        $info = LdapUserInfo::fromLdapEntry(self::ENTRY, 'jdoe', 'displayName', 'mail');

        $this->assertSame('jdoe', $info->getUsername());
        $this->assertSame('John Doe', $info->getDisplayName());
        $this->assertSame('John Doe', $info->getFullName());
        $this->assertSame('jdoe@example.com', $info->getEmail(), 'First value of a multi-valued attribute wins');
        $this->assertSame('uid=jdoe,ou=users,dc=example,dc=com', $info->getSubject());
        $this->assertTrue($info->isValid());
    }

    public function testMissingAttributesResolveToEmptyStrings(): void
    {
        $info = LdapUserInfo::fromLdapEntry(self::ENTRY, 'jdoe', 'cn', 'otherMailbox');

        $this->assertSame('', $info->getDisplayName());
        $this->assertSame('', $info->getEmail());
    }

    public function testEmptyAttributeNamesAreSkipped(): void
    {
        $info = LdapUserInfo::fromLdapEntry(self::ENTRY, 'jdoe', '', '');

        $this->assertSame('', $info->getDisplayName());
        $this->assertSame('', $info->getEmail());
    }

    public function testProviderAndDefaults(): void
    {
        $info = LdapUserInfo::fromLdapEntry(self::ENTRY, 'jdoe', 'displayName', 'mail');

        $this->assertSame('ldap', $info->getProviderId());
        $this->assertSame('', $info->getFirstName());
        $this->assertSame('', $info->getLastName());
        $this->assertSame([], $info->getGroups());
        $this->assertFalse($info->hasGroup('admins'));
        $this->assertSame([], $info->getRawData());
    }

    public function testEmptyUsernameIsInvalid(): void
    {
        $info = new LdapUserInfo('');

        $this->assertFalse($info->isValid());
    }
}
