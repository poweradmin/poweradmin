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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\LdapAuthenticator;
use Poweradmin\Application\Service\LoggingService;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\SqlAuthenticator;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionClass;

/**
 * With account lockout on, only real usernames used to answer "Account is temporarily
 * locked"; a locked account now gets the same text as an unknown one.
 */
class LockedAccountMessageTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['userlogin' => 'alice'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testSqlLoginAnswersALockedAccountWithTheGenericMessage(): void
    {
        $shown = null;
        $authenticator = $this->build(SqlAuthenticator::class, [
            'serverParams' => ['REMOTE_ADDR' => '192.0.2.10'],
        ], $shown);

        $authenticator->authenticate();

        $this->assertSame('Authentication failed!', $shown?->getMessage());
    }

    public function testLdapLoginAnswersALockedAccountWithTheGenericMessage(): void
    {
        $shown = null;
        $context = $this->createMock(UserContextService::class);
        $context->method('getLoggedInUsername')->willReturn('alice');
        $authenticator = $this->build(LdapAuthenticator::class, [
            'serverParams' => ['REMOTE_ADDR' => '192.0.2.10'],
            'userContextService' => $context,
        ], $shown);

        $authenticator->authenticate();

        $this->assertSame('Authentication failed!', $shown?->getMessage());
    }

    public function testLockedAccountsSpendTheHashTimeOfAnUnknownName(): void
    {
        $config = $this->config(10);
        $_SESSION['userpwd'] = (new PasswordEncryptionService('test-session-key'))->encrypt('guess');

        $context = $this->createMock(UserContextService::class);
        $context->method('getLoggedInUsername')->willReturn('alice');
        $context->method('getSessionData')->willReturn($_SESSION['userpwd']);

        $server = ['REMOTE_ADDR' => '192.0.2.10'];
        $cases = [
            [SqlAuthenticator::class, ['serverParams' => $server, 'configManager' => $config]],
            [LdapAuthenticator::class, ['serverParams' => $server, 'configManager' => $config, 'userContextService' => $context]],
        ];

        foreach ($cases as [$class, $properties]) {
            $shown = null;
            $authenticator = $this->build($class, $properties, $shown);

            $start = microtime(true);
            $authenticator->authenticate();

            // A bcrypt cost-10 verify takes tens of milliseconds; the bare lock refusal took microseconds
            $this->assertGreaterThan(0.01, microtime(true) - $start, $class);
        }
    }

    private function config(int $cost): ConfigurationManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(static fn(string $group, string $key, mixed $default = null): mixed => [
            'security.session_key' => 'test-session-key',
            'security.password_encryption' => 'bcrypt',
            'security.password_cost' => $cost,
        ]["$group.$key"] ?? $default);

        return $config;
    }

    /**
     * @param class-string<LoggingService> $class
     * @param array<string, mixed> $properties
     */
    private function build(string $class, array $properties, ?SessionEntity &$shown): object
    {
        $reflection = new ReflectionClass($class);
        $authenticator = $reflection->newInstanceWithoutConstructor();

        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn(true);

        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('auth')->willReturnCallback(static function (SessionEntity $entity) use (&$shown): void {
            $shown = $entity;
        });

        $properties += [
            'configManager' => $this->config(4),
            'loginAttemptService' => $attempts,
            $class === LdapAuthenticator::class ? 'authenticationService' : 'authService' => $auth,
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($authenticator, $value);
        }

        $base = new ReflectionClass(LoggingService::class);
        $base->getProperty('logger')->setValue($authenticator, $this->createMock(Logger::class));
        $base->getProperty('className')->setValue($authenticator, '');

        return $authenticator;
    }
}
