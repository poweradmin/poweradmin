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


namespace Poweradmin\Tests\Unit\Domain\Enum;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Service\UserContextService;

class AuthMethodTest extends TestCase
{
    /**
     * These constants are now defined from the enum, so they cannot drift. The
     * assertions pin the stored `users.auth_method` values all the same.
     */
    public function testAliasConstantsMatchTheEnum(): void
    {
        $this->assertSame('sql', UserProvisioningService::AUTH_METHOD_SQL);
        $this->assertSame('ldap', UserProvisioningService::AUTH_METHOD_LDAP);
        $this->assertSame('oidc', UserProvisioningService::AUTH_METHOD_OIDC);
        $this->assertSame('saml', UserProvisioningService::AUTH_METHOD_SAML);
    }

    public function testExternalAuthMethodsListMatchesIsExternal(): void
    {
        $derived = array_values(array_map(
            fn(AuthMethod $m) => $m->value,
            array_filter(AuthMethod::cases(), fn(AuthMethod $m) => $m->isExternal())
        ));

        sort($derived);
        $declared = UserContextService::EXTERNAL_AUTH_METHODS;
        sort($declared);

        $this->assertSame($declared, $derived);
    }

    public function testOnlySqlAllowsALocalPassword(): void
    {
        $this->assertTrue(AuthMethod::SQL->allowsLocalPassword());
        $this->assertFalse(AuthMethod::LDAP->allowsLocalPassword());
        $this->assertFalse(AuthMethod::OIDC->allowsLocalPassword());
        $this->assertFalse(AuthMethod::SAML->allowsLocalPassword());
    }

    /**
     * LDAP is IdP-managed only while ldap.sync_user_info is on; OIDC and SAML
     * always are, because they re-sync identity on every login.
     */
    public function testIdpManagedDependsOnLdapSync(): void
    {
        $this->assertTrue(AuthMethod::OIDC->isIdpManaged());
        $this->assertTrue(AuthMethod::SAML->isIdpManaged());
        $this->assertFalse(AuthMethod::SQL->isIdpManaged(true));

        $this->assertFalse(AuthMethod::LDAP->isIdpManaged());
        $this->assertTrue(AuthMethod::LDAP->isIdpManaged(true));
    }

    public function testFromDbFallsBackToSql(): void
    {
        $this->assertSame(AuthMethod::OIDC, AuthMethod::fromDb('oidc'));
        $this->assertSame(AuthMethod::SQL, AuthMethod::fromDb(null));
        $this->assertSame(AuthMethod::SQL, AuthMethod::fromDb(''));
        $this->assertSame(AuthMethod::SQL, AuthMethod::fromDb('kerberos'));
    }

    public function testResolveSetsLdapWhenTheFlagIsOn(): void
    {
        $this->assertSame(AuthMethod::LDAP, AuthMethod::resolve(true, null));
        $this->assertSame(AuthMethod::LDAP, AuthMethod::resolve(true, 'oidc'));
    }

    /**
     * Turning the LDAP flag off must not downgrade an SSO account to sql.
     */
    public function testResolvePreservesSsoWhenLdapIsSwitchedOff(): void
    {
        $this->assertSame(AuthMethod::OIDC, AuthMethod::resolve(false, 'oidc'));
        $this->assertSame(AuthMethod::SAML, AuthMethod::resolve(false, 'saml'));
    }

    public function testResolveFallsBackToSql(): void
    {
        $this->assertSame(AuthMethod::SQL, AuthMethod::resolve(false, null));
        $this->assertSame(AuthMethod::SQL, AuthMethod::resolve(false, 'ldap'));
        $this->assertSame(AuthMethod::SQL, AuthMethod::resolve(false, 'sql'));
        $this->assertSame(AuthMethod::SQL, AuthMethod::resolve(false, 'nonsense'));
    }

    public function testValidation(): void
    {
        $this->assertTrue(AuthMethod::isValid('saml'));
        $this->assertFalse(AuthMethod::isValid('SAML'));
        $this->assertFalse(AuthMethod::isValid('kerberos'));
    }
}
