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
 * Tests for EditUserController::isIdpManaged() and resolveIdentityFields(), which
 * keep fullname/email read-only for OIDC/SAML users (their IdP sync overwrites
 * local edits). LDAP never syncs these fields, so LDAP accounts stay editable.
 */
class EditUserControllerIdentityFieldsTest extends TestCase
{
    public function testIsIdpManagedForEachAuthMethod(): void
    {
        // Only OIDC/SAML sync identity fields on login.
        $this->assertTrue(EditUserController::isIdpManaged('oidc'));
        $this->assertTrue(EditUserController::isIdpManaged('saml'));

        // LDAP has no attribute sync, so its identity fields stay editable.
        $this->assertFalse(EditUserController::isIdpManaged('ldap'));

        $this->assertFalse(EditUserController::isIdpManaged('sql'));
        $this->assertFalse(EditUserController::isIdpManaged(null));
    }

    public function testManagedAccountsKeepStoredIdentityAndDiscardSubmitted(): void
    {
        foreach (['oidc', 'saml'] as $authType) {
            $userData = [
                'auth_type' => $authType,
                'fullname' => 'Stored Name',
                'email' => 'stored@example.com',
            ];

            $result = EditUserController::resolveIdentityFields(
                $userData,
                'Attacker Supplied',
                'attacker@evil.example',
            );

            $this->assertSame('Stored Name', $result['fullname'], $authType);
            $this->assertSame('stored@example.com', $result['email'], $authType);
        }
    }

    public function testLdapAccountUsesSubmittedValues(): void
    {
        $userData = [
            'auth_type' => 'ldap',
            'fullname' => 'Stored Name',
            'email' => 'stored@example.com',
        ];

        // LDAP never syncs fullname/email, so admin edits must take effect.
        $result = EditUserController::resolveIdentityFields(
            $userData,
            'New Name',
            'new@example.com',
        );

        $this->assertSame('New Name', $result['fullname']);
        $this->assertSame('new@example.com', $result['email']);
    }

    public function testInternalUserKeepsSubmittedValues(): void
    {
        $userData = [
            'auth_type' => 'sql',
            'fullname' => 'Stored Name',
            'email' => 'stored@example.com',
        ];

        $result = EditUserController::resolveIdentityFields(
            $userData,
            'New Name',
            'new@example.com',
        );

        $this->assertSame('New Name', $result['fullname']);
        $this->assertSame('new@example.com', $result['email']);
    }

    public function testMissingAuthTypeIsTreatedAsInternal(): void
    {
        $result = EditUserController::resolveIdentityFields(
            ['fullname' => 'Stored Name', 'email' => 'stored@example.com'],
            'New Name',
            'new@example.com',
        );

        $this->assertSame('New Name', $result['fullname']);
        $this->assertSame('new@example.com', $result['email']);
    }

    public function testManagedAccountWithMissingStoredFieldsYieldsEmptyStrings(): void
    {
        $result = EditUserController::resolveIdentityFields(
            ['auth_type' => 'oidc'],
            'Submitted',
            'submitted@example.com',
        );

        $this->assertSame('', $result['fullname']);
        $this->assertSame('', $result['email']);
    }
}
