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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Service\SessionAuthenticator;
use ReflectionClass;

/**
 * A login waiting for its second factor has the same idle limit as a signed-in session.
 */
#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorIdleTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function endIdle(array $session, bool $expectLogout): bool
    {
        $authService = $this->createMock(AuthenticationService::class);
        $authService->expects($expectLogout ? $this->once() : $this->never())->method('logout');

        $reflection = new ReflectionClass(SessionAuthenticator::class);
        $authenticator = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('authService')->setValue($authenticator, $authService);
        $reflection->getParentClass()->getProperty('logger')->setValue($authenticator, $this->createMock(Logger::class));

        $_SESSION = $session;

        return $reflection->getMethod('endIdleSession')->invoke($authenticator, 1800);
    }

    #[Test]
    public function anIdlePendingLoginIsCleared(): void
    {
        $this->assertTrue($this->endIdle(['pending_userid' => 7, 'lastmod' => time() - 3600], true));
    }

    #[Test]
    public function aRecentPendingLoginIsKept(): void
    {
        $this->assertFalse($this->endIdle(['pending_userid' => 7, 'lastmod' => time() - 60], false));
    }

    #[Test]
    public function aSessionWithoutAnIdentityIsLeftAlone(): void
    {
        $this->assertFalse($this->endIdle(['userlogin' => 'alice', 'lastmod' => time() - 3600], false));
    }
}
