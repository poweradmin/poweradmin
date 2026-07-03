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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditUserController;

/**
 * Tests for EditUserController::resolveAuthFields() (#1327), which keeps
 * username and the LDAP flag read-only on restricted self-edits.
 */
class EditUserControllerAuthFieldsTest extends TestCase
{
    private const STORED_LDAP_USER = [
        'username' => 'jdoe',
        'use_ldap' => 1,
    ];

    public function testUnrestrictedEditUsesSubmittedValues(): void
    {
        $auth = EditUserController::resolveAuthFields(self::STORED_LDAP_USER, false, 'renamed', false);

        $this->assertSame('renamed', $auth['username']);
        $this->assertFalse($auth['use_ldap']);
    }

    public function testRestrictedSelfEditKeepsStoredUsername(): void
    {
        $auth = EditUserController::resolveAuthFields(self::STORED_LDAP_USER, true, 'admin2', true);

        $this->assertSame('jdoe', $auth['username']);
    }

    public function testRestrictedSelfEditCannotDisableLdap(): void
    {
        $auth = EditUserController::resolveAuthFields(self::STORED_LDAP_USER, true, 'jdoe', false);

        $this->assertTrue($auth['use_ldap'], 'Stored LDAP flag survives an unchecked checkbox');
    }

    public function testRestrictedSelfEditCannotEnableLdap(): void
    {
        $stored = ['username' => 'jdoe', 'use_ldap' => 0];

        $auth = EditUserController::resolveAuthFields($stored, true, 'jdoe', true);

        $this->assertFalse($auth['use_ldap']);
    }

    public function testRestrictedSelfEditWithoutLdapColumnKeepsSubmittedFlag(): void
    {
        // LDAP disabled in config -> no use_ldap column; submitted flag wins.
        $stored = ['username' => 'jdoe'];

        $auth = EditUserController::resolveAuthFields($stored, true, 'jdoe', false);

        $this->assertFalse($auth['use_ldap']);
    }
}
