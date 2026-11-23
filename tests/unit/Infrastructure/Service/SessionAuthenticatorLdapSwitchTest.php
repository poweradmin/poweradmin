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

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Service\SessionAuthenticator;
use ReflectionClass;

/**
 * A provider session ends when its account has been moved to LDAP sign-in; SQL and SSO accounts are untouched.
 */
#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorLdapSwitchTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public static function accounts(): array
    {
        return [
            'sql account that also uses SSO' => ['sql', 1, false],
            'oidc account' => ['oidc', 1, false],
            'saml account' => ['saml', 1, false],
            'ldap account' => ['ldap', 1, true],
            'disabled ldap account' => ['ldap', 0, true],
        ];
    }

    #[Test]
    #[DataProvider('accounts')]
    public function sessionEndsWhenTheStoredMethodIsLdap(string $method, int $active, bool $ends): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, active INTEGER, auth_method TEXT)');
        $db->exec("INSERT INTO users VALUES (5, 'alice', {$active}, '{$method}')");

        $flash = null;
        $authService = $this->createMock(AuthenticationService::class);
        $authService->expects($ends || $active === 0 ? $this->once() : $this->never())->method('logout')
            ->willReturnCallback(function (SessionEntity $entity) use (&$flash): void {
                $flash = $entity->getMessage();
            });

        $reflection = new ReflectionClass(SessionAuthenticator::class);
        $authenticator = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('db')->setValue($authenticator, $db);
        $reflection->getProperty('authService')->setValue($authenticator, $authService);
        $reflection->getParentClass()->getProperty('logger')->setValue($authenticator, $this->createMock(Logger::class));

        $_SESSION = ['userid' => 5, 'userlogin' => 'alice'];
        $reflection->getMethod('endSessionOfDisabledAccount')->invoke($authenticator);

        if ($ends && $active === 1) {
            $this->assertSame('Session expired, please login again.', $flash);
        }
    }
}
