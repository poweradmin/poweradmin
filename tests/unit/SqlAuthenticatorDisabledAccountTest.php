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

namespace unit;

use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\LoggingService;
use Poweradmin\Application\Service\SqlAuthenticator;
use Poweradmin\Application\Service\UserEventLogger;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionClass;

/**
 * A disabled account used to keep its session, so re-enabling it later brought the
 * old session back. The session must end the moment the disabled flag is seen.
 */
class SqlAuthenticatorDisabledAccountTest extends TestCase
{
    private const PASSWORD = 'Secret-pass-123';
    private const KEY = 'unit-test-session-key-0123456789ab';

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testDisabledAccountEndsTheSessionInsteadOfKeepingIt(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects($this->once())->method('logout')
            ->with($this->callback(static fn(SessionEntity $e): bool => $e->getMessage() === 'The user account is disabled.'));
        $auth->expects($this->never())->method('auth');

        $this->authenticateWith($auth, 0);
    }

    public function testActiveAccountDoesNotEndTheSession(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects($this->never())->method('logout');

        $this->authenticateWith($auth, 1);

        $this->assertSame(1, $_SESSION['userid']);
    }

    private function authenticateWith(AuthenticationService $auth, int $active): void
    {
        $row = [
            'id' => 1,
            'fullname' => 'Alice',
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'active' => $active,
        ];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        $db = $this->createMock(PDOLayer::class);
        $db->method('prepare')->willReturn($stmt);

        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturnCallback(static fn($key) => $key === 'session_key' ? self::KEY : null);

        $_SESSION = [
            'userlogin' => 'alice',
            'userpwd' => (new PasswordEncryptionService(self::KEY))->encrypt(self::PASSWORD),
        ];

        $reflection = new ReflectionClass(SqlAuthenticator::class);
        $authenticator = $reflection->newInstanceWithoutConstructor();
        foreach (
            [
                'db' => $db,
                'config' => $config,
                'userEventLogger' => $this->createMock(UserEventLogger::class),
                'authenticationService' => $auth,
                'csrfTokenService' => $this->createMock(CsrfTokenService::class),
            ] as $name => $value
        ) {
            $reflection->getProperty($name)->setValue($authenticator, $value);
        }
        $base = new ReflectionClass(LoggingService::class);
        $base->getProperty('logger')->setValue($authenticator, $this->createMock(Logger::class));
        $base->getProperty('className')->setValue($authenticator, '');

        // An active account reaches session_regenerate_id(), which warns without a real session
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $authenticator->authenticate();
        } finally {
            restore_error_handler();
        }
    }
}
