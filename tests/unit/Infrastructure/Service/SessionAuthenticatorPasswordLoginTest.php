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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Service\SessionAuthenticator;

#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorPasswordLoginTest extends TestCase
{
    public static function refusedLogins(): array
    {
        return [
            'oidc account' => ['oidc', true, true],
            'saml account' => ['saml', true, false],
        ];
    }

    #[Test]
    #[DataProvider('refusedLogins')]
    public function ssoAccountsFailAPasswordLoginLikeAWrongPassword(string $method, bool $isLogin, bool $ldap): void
    {
        $this->assertNotNull(SessionAuthenticator::passwordLoginRefusal($method, $isLogin, $ldap));
    }

    #[Test]
    public function ldapAccountFailsAPasswordLoginWhileLdapIsDisabled(): void
    {
        $this->assertNotNull(SessionAuthenticator::passwordLoginRefusal('ldap', true, false));
        $this->assertNull(SessionAuthenticator::passwordLoginRefusal('ldap', false, false));
    }

    #[Test]
    public function existingSsoSessionsKeepWorking(): void
    {
        $this->assertNull(SessionAuthenticator::passwordLoginRefusal('oidc', false, true));
        $this->assertNull(SessionAuthenticator::passwordLoginRefusal('saml', false, false));
    }

    #[Test]
    public function sqlAndEnabledLdapLoginsProceed(): void
    {
        $this->assertNull(SessionAuthenticator::passwordLoginRefusal('sql', true, false));
        $this->assertNull(SessionAuthenticator::passwordLoginRefusal('ldap', true, true));
    }
}
