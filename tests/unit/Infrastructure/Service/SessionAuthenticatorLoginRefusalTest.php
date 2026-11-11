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
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\RecaptchaService;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Service\SessionAuthenticator;
use ReflectionClass;

/**
 * A password login for an OIDC, SAML or LDAP-while-disabled account must look
 * like a wrong password on an SQL account.
 */
#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorLoginRefusalTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    public static function passwordlessMethods(): array
    {
        return [
            'oidc' => ['oidc', true],
            'saml' => ['saml', true],
        ];
    }

    #[Test]
    #[DataProvider('passwordlessMethods')]
    public function aPasswordLoginGetsTheGenericFailure(string $method, bool $ldapEnabled): void
    {
        $messages = [];
        $this->attempt($method, $ldapEnabled, true, $messages);

        $this->assertSame(['Authentication failed!'], $messages);
        $this->assertArrayNotHasKey(SessionKeys::USERPWD, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::USERLOGIN, $_SESSION);
    }

    #[Test]
    public function anExistingSsoSessionKeepsWorking(): void
    {
        $messages = [];
        $this->attempt('oidc', true, false, $messages);

        $this->assertSame([], $messages);
    }

    /**
     * @param list<string> $messages flash messages passed to AuthenticationService::auth()
     */
    private function attempt(string $method, bool $ldapEnabled, bool $isLogin, array &$messages): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE users (id INTEGER, username TEXT, auth_method TEXT, active INTEGER)');
        $db->exec("INSERT INTO users VALUES (1, 'sso-user', '" . $method . "', 1)");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function ($section, $key, $default = null) use ($ldapEnabled) {
            $values = [
                'login_token_validation' => false,
                'global_token_validation' => false,
                'password_cost' => 4,
                'session_key' => str_repeat('k', 32),
                'enabled' => $ldapEnabled,
            ];
            return $values[$key] ?? $default;
        });

        $recaptcha = $this->createMock(RecaptchaService::class);
        $recaptcha->method('isEnabled')->willReturn(false);

        $authService = $this->createMock(AuthenticationService::class);
        $authService->method('auth')->willReturnCallback(function (SessionEntity $entity) use (&$messages) {
            $messages[] = $entity->getMessage();
        });

        $reflection = new ReflectionClass(SessionAuthenticator::class);
        $authenticator = $reflection->newInstanceWithoutConstructor();
        foreach (
            [
                'db' => $db,
                'configManager' => $config,
                'csrfTokenService' => $this->createMock(CsrfTokenService::class),
                'recaptchaService' => $recaptcha,
                'sessionService' => $this->createMock(SessionService::class),
                'authService' => $authService,
            ] as $name => $value
        ) {
            $reflection->getProperty($name)->setValue($authenticator, $value);
        }
        $reflection->getParentClass()->getProperty('logger')->setValue($authenticator, $this->createMock(Logger::class));

        $_SESSION = [SessionKeys::USERLOGIN => 'sso-user'];
        $_POST = [];
        if ($isLogin) {
            $_POST = ['authenticate' => '1', 'username' => 'sso-user', 'password' => 'secret'];
            $_SESSION[SessionKeys::USERPWD] = 'posted';
        }

        $authenticator->authenticate();
    }
}
