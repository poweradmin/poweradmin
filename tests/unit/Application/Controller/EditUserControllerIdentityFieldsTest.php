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
 * keep fullname/email read-only for users whose account is IdP-managed after the
 * edit (OIDC/SAML always; LDAP only while it stays enabled).
 */
class EditUserControllerIdentityFieldsTest extends TestCase
{
    public function testIsIdpManagedForEachAuthMethod(): void
    {
        // OIDC/SAML are always managed, regardless of the LDAP checkbox.
        $this->assertTrue(EditUserController::isIdpManaged('oidc', false));
        $this->assertTrue(EditUserController::isIdpManaged('saml', false));
        $this->assertTrue(EditUserController::isIdpManaged('oidc', true));

        // LDAP is managed only while LDAP stays enabled.
        $this->assertTrue(EditUserController::isIdpManaged('ldap', true));
        $this->assertFalse(EditUserController::isIdpManaged('ldap', false));

        // Internal accounts are managed only when being converted to LDAP.
        $this->assertFalse(EditUserController::isIdpManaged('sql', false));
        $this->assertTrue(EditUserController::isIdpManaged('sql', true));
        $this->assertFalse(EditUserController::isIdpManaged(null, false));
    }

    public function testManagedAccountsKeepStoredIdentityAndDiscardSubmitted(): void
    {
        $cases = [
            ['oidc', false],
            ['saml', false],
            ['ldap', true],
        ];

        foreach ($cases as [$authType, $useLdap]) {
            $userData = [
                'auth_type' => $authType,
                'fullname' => 'Stored Name',
                'email' => 'stored@example.com',
            ];

            $result = EditUserController::resolveIdentityFields(
                $userData,
                $useLdap,
                'Attacker Supplied',
                'attacker@evil.example',
            );

            $label = "$authType/useLdap=" . ($useLdap ? '1' : '0');
            $this->assertSame('Stored Name', $result['fullname'], $label);
            $this->assertSame('stored@example.com', $result['email'], $label);
        }
    }

    public function testLdapAccountConvertedToLocalUsesSubmittedValues(): void
    {
        $userData = [
            'auth_type' => 'ldap',
            'fullname' => 'Stored Name',
            'email' => 'stored@example.com',
        ];

        // Unchecking "Use LDAP" converts to a local account, so the admin's
        // submitted values must take effect.
        $result = EditUserController::resolveIdentityFields(
            $userData,
            false,
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
            false,
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
            false,
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
            false,
            'Submitted',
            'submitted@example.com',
        );

        $this->assertSame('', $result['fullname']);
        $this->assertSame('', $result['email']);
    }
}
