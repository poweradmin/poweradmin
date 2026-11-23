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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Application\Service\UserEventLogger;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Enum\LoginFailureReason;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\DynamicDnsRepositoryInterface;
use Poweradmin\Domain\Service\DynamicDnsAuthenticationService;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Service\BasicAuthenticationMiddleware;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Basic auth and dynamic DNS failures write the login_failed line the web sign-in writes,
 * so the documented fail2ban filter matches them.
 */
final class StatelessLoginFailureLogTest extends TestCase
{
    #[Test]
    public function basicAuthLogsAnUnknownUser(): void
    {
        $logger = $this->createMock(UserEventLogger::class);
        $logger->expects($this->once())->method('logFailedAuth')
            ->with(AuthMethod::SQL, LoginFailureReason::NO_SUCH_USER, 'nobody');

        $this->assertSame(0, $this->basicAuth('nobody', 'wrong', $logger));
    }

    #[Test]
    public function basicAuthLogsAWrongPassword(): void
    {
        $logger = $this->createMock(UserEventLogger::class);
        $logger->expects($this->once())->method('logFailedAuth')
            ->with(AuthMethod::SQL, LoginFailureReason::WRONG_PASSWORD, 'alice');

        $this->assertSame(0, $this->basicAuth('alice', 'wrong', $logger));
    }

    #[Test]
    public function basicAuthLogsADisabledAccount(): void
    {
        $logger = $this->createMock(UserEventLogger::class);
        $logger->expects($this->once())->method('logFailedAuth')
            ->with(AuthMethod::SQL, LoginFailureReason::ACCOUNT_DISABLED, 'off');

        $this->assertSame(0, $this->basicAuth('off', 'wrong', $logger));
    }

    #[Test]
    public function aDirectoryOutageIsLoggedAsAnErrorNotAFailedLogin(): void
    {
        $logger = $this->createMock(UserEventLogger::class);
        $logger->expects($this->never())->method('logFailedAuth');
        $logger->expects($this->once())->method('logAuthError')
            ->with(AuthMethod::LDAP, LoginFailureReason::LDAP_SEARCH_FAILED, 'ldapuser');

        $this->assertSame(0, $this->basicAuth('ldapuser', 'pw', $logger, ldapUri: 'ldap://127.0.0.1:1'));
    }

    #[Test]
    public function aMissingLdapConfigurationIsAlsoAnOutage(): void
    {
        $logger = $this->createMock(UserEventLogger::class);
        $logger->expects($this->never())->method('logFailedAuth');
        $logger->expects($this->once())->method('logAuthError');

        $this->assertSame(0, $this->basicAuth('ldapuser', 'pw', $logger, ldapUri: ''));
    }

    #[Test]
    public function aDirectoryOutageDoesNotCountTowardsLockoutButAWrongPasswordDoes(): void
    {
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn(false);
        $attempts->expects($this->never())->method('recordAttempt');
        $this->assertSame(0, $this->basicAuth('ldapuser', 'pw', $this->createMock(UserEventLogger::class), ldapUri: 'ldap://127.0.0.1:1', attempts: $attempts));

        // An empty password is refused as a wrong password without contacting the directory
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn(false);
        $attempts->expects($this->once())->method('recordAttempt')->with('ldapuser', $this->anything(), false);
        $this->assertSame(0, $this->basicAuth('ldapuser', '', $this->createMock(UserEventLogger::class), ldapUri: 'ldap://127.0.0.1:1', attempts: $attempts));
    }

    #[Test]
    public function ldapUserFailuresThatAreGuessesStayFailedLogins(): void
    {
        foreach ([LoginFailureReason::NO_SUCH_USER, LoginFailureReason::DUPLICATE_USERS, LoginFailureReason::WRONG_PASSWORD] as $reason) {
            $logger = $this->createMock(UserEventLogger::class);
            $logger->expects($this->once())->method('logFailedAuth')->with(AuthMethod::LDAP, $reason, 'ldapuser');
            $logger->expects($this->never())->method('logAuthError');

            $middleware = (new ReflectionClass(BasicAuthenticationMiddleware::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(BasicAuthenticationMiddleware::class, 'userEventLogger'))->setValue($middleware, $logger);
            (new ReflectionMethod($middleware, 'logFailure'))->invoke($middleware, 'ldapuser', $reason, AuthMethod::LDAP);
        }
    }

    #[Test]
    public function dynamicDnsLogsAWrongPasswordAndAnUnknownUser(): void
    {
        $logger = $this->createMock(UserEventLogger::class);
        $calls = [];
        $logger->method('logFailedAuth')->willReturnCallback(function ($method, $reason, $user) use (&$calls) {
            $calls[] = [$reason, $user];
        });

        $repo = $this->createMock(DynamicDnsRepositoryInterface::class);
        $repo->method('findUserByUsernameWithDynamicDnsPermissions')
            ->willReturnCallback(fn(string $name) => $name === 'ddns' ? new User(5, 'hash', false) : null);
        $auth = $this->createMock(UserAuthenticationService::class);
        $auth->method('verifyPassword')->willReturn(false);

        $service = new DynamicDnsAuthenticationService($repo, $auth, null, $logger);
        $this->assertNull($service->authenticateUser(new DynamicDnsRequest('ddns', 'bad', 'h.example.com', '192.0.2.1', '', false, 'ua'), '198.51.100.1'));
        $this->assertNull($service->authenticateUser(new DynamicDnsRequest('ghost', 'bad', 'h.example.com', '192.0.2.1', '', false, 'ua'), '198.51.100.1'));

        $this->assertSame([[LoginFailureReason::WRONG_PASSWORD, 'ddns'], [LoginFailureReason::NO_SUCH_USER, 'ghost']], $calls);
    }

    #[Test]
    public function theLineCarriesTheGivenNameEncodedAndNoSecrets(): void
    {
        $_SESSION = [];
        $reflection = new ReflectionClass(UserEventLogger::class);
        $logger = $reflection->newInstanceWithoutConstructor();
        $captor = new class extends LegacyLogger {
            public string $line = '';

            public function __construct()
            {
                // The captor never touches the database
            }

            public function logWarn(string $message, ?int $zone_id = null): void
            {
                $this->line = $message;
            }
        };
        $reflection->getProperty('logger')->setValue($logger, $captor);
        $reflection->getProperty('ipRetriever')->setValue($logger, new IpAddressRetriever(['REMOTE_ADDR' => '1.2.3.4']));

        $logger->logFailedAuth(AuthMethod::SQL, LoginFailureReason::WRONG_PASSWORD, "bob client_ip:9.9.9.9\n");

        $this->assertSame(
            'client_ip:1.2.3.4 user:bob%20client_ip%3A9.9.9.9? operation:login_failed auth_method:sql reason:wrong_password',
            $captor->line
        );
    }

    #[Test]
    public function aPasswordWithANulByteIsAWrongPasswordNotAServerError(): void
    {
        $logger = $this->createMock(UserEventLogger::class);
        $logger->expects($this->once())->method('logFailedAuth')
            ->with(AuthMethod::LDAP, LoginFailureReason::WRONG_PASSWORD, 'ldapuser');
        $logger->expects($this->never())->method('logAuthError');
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn(false);
        $attempts->expects($this->once())->method('recordAttempt')->with('ldapuser', $this->anything(), false);

        $this->assertSame(0, $this->basicAuth('ldapuser', "pw\0x", $logger, ldapUri: 'ldap://127.0.0.1:1', attempts: $attempts));
    }

    private function basicAuth(string $username, string $password, UserEventLogger $logger, ?string $ldapUri = null, ?LoginAttemptService $attempts = null): int
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE users (id INTEGER, username TEXT, password TEXT, use_ldap INTEGER, active INTEGER)');
        $db->exec("INSERT INTO users VALUES (1, 'alice', 'x', 0, 1)");
        $db->exec("INSERT INTO users VALUES (2, 'off', 'x', 0, 0)");
        $db->exec("INSERT INTO users VALUES (3, 'ldapuser', '', 1, 1)");

        $authService = $this->createMock(UserAuthenticationService::class);
        $authService->method('dummyVerificationHash')->willReturn('dummy');
        $authService->method('verifyPassword')->willReturn(false);
        if ($attempts === null) {
            $attempts = $this->createMock(LoginAttemptService::class);
            $attempts->method('isAccountLocked')->willReturn(false);
        }

        $reflection = new ReflectionClass(BasicAuthenticationMiddleware::class);
        $middleware = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('db')->setValue($middleware, $db);
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(static fn(string $group, string $key, mixed $default = null) => match ($group . '.' . $key) {
            'ldap.enabled' => true,
            'ldap.uri' => $ldapUri ?? '',
            'ldap.base_dn' => 'dc=example,dc=com',
            default => $default,
        });
        $reflection->getProperty('config')->setValue($middleware, $config);
        $reflection->getProperty('loginAttemptService')->setValue($middleware, $attempts);
        $reflection->getProperty('authService')->setValue($middleware, $authService);
        $reflection->getProperty('userEventLogger')->setValue($middleware, $logger);

        return (new ReflectionMethod($middleware, 'authenticateAndGetUserId'))->invoke($middleware, $username, $password);
    }
}
