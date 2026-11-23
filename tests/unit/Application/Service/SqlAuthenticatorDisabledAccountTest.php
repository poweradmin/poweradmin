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

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\LoggingService;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\SqlAuthenticator;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionClass;

/**
 * A disabled account used to keep its session, so re-enabling it later brought the
 * old session back. The session must end the moment the disabled flag is seen.
 */
class SqlAuthenticatorDisabledAccountTest extends TestCase
{
    private const PASSWORD = 'Secret-pass-123';

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
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT, password TEXT, active INTEGER, email TEXT, use_ldap INTEGER)');
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $pdo->prepare('INSERT INTO users VALUES (1, :u, :f, :p, :a, :e, 0)')
            ->execute(['u' => 'alice', 'f' => 'Alice', 'p' => $hash, 'a' => $active, 'e' => 'alice@example.com']);

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(static fn($section, $key, $default = null) => match ($key) {
            'session_key' => 'unit-test-session-key-0123456789ab',
            'password_cost' => 4,
            'mfa.enabled' => false,
            default => $default,
        });

        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn(false);

        $_SESSION = [
            'userlogin' => 'alice',
            'userpwd' => (new PasswordEncryptionService('unit-test-session-key-0123456789ab'))->encrypt(self::PASSWORD),
        ];

        $reflection = new ReflectionClass(SqlAuthenticator::class);
        $authenticator = $reflection->newInstanceWithoutConstructor();
        $csrf = $this->createMock(\Poweradmin\Application\Service\CsrfTokenService::class);
        foreach (
            [
                'connection' => $pdo,
                'configManager' => $config,
                'authService' => $auth,
                'loginAttemptService' => $attempts,
                'csrfTokenService' => $csrf,
                'serverParams' => ['REMOTE_ADDR' => '192.0.2.10'],
            ] as $name => $value
        ) {
            $reflection->getProperty($name)->setValue($authenticator, $value);
        }
        $base = new ReflectionClass(LoggingService::class);
        $base->getProperty('logger')->setValue($authenticator, $this->createMock(Logger::class));
        $base->getProperty('className')->setValue($authenticator, '');

        $authenticator->authenticate();
    }
}
