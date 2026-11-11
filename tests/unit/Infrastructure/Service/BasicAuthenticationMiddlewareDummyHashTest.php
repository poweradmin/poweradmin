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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\BasicAuthenticationMiddleware;
use ReflectionClass;
use ReflectionMethod;

/**
 * A Basic Auth request must cost the same whether the username is unknown or
 * belongs to a locked account, so response time does not reveal which names exist.
 */
#[CoversClass(BasicAuthenticationMiddleware::class)]
final class BasicAuthenticationMiddlewareDummyHashTest extends TestCase
{
    #[Test]
    public function anUnknownUsernameRunsTheDummyHash(): void
    {
        $this->assertSame(0, $this->authenticate('nobody', false, 1));
    }

    private function authenticate(string $username, bool $locked, int $expectedHashes): int
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE users (id INTEGER, username TEXT, password TEXT, use_ldap INTEGER, active INTEGER)');
        $db->exec("INSERT INTO users VALUES (1, 'locked-user', 'x', 0, 1)");

        $authService = $this->createMock(UserAuthenticationService::class);
        $authService->method('dummyVerificationHash')->willReturn('dummy');
        $authService->expects($this->exactly($expectedHashes))->method('verifyPassword')
            ->with('pw', 'dummy')->willReturn(false);

        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn($locked);

        $reflection = new ReflectionClass(BasicAuthenticationMiddleware::class);
        $middleware = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('db')->setValue($middleware, $db);
        $reflection->getProperty('config')->setValue($middleware, $this->createMock(ConfigurationManager::class));
        $reflection->getProperty('loginAttemptService')->setValue($middleware, $attempts);
        $reflection->getProperty('authService')->setValue($middleware, $authService);

        return (new ReflectionMethod($middleware, 'authenticateAndGetUserId'))->invoke($middleware, $username, 'pw');
    }
}
