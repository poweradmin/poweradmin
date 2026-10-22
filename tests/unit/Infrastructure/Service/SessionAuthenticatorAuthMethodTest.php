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
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Service\SessionAuthenticator;
use ReflectionClass;

/**
 * The login POST ends the session, so a user with no users row is routed by the
 * LDAP settings alone: with auto-provisioning on they must reach LDAP.
 */
#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorAuthMethodTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public static function unknownUserRouting(): array
    {
        return [
            'ldap and auto-provisioning on' => [true, true, 'ldap'],
            'auto-provisioning off' => [true, false, 'sql'],
            'ldap off' => [false, true, 'sql'],
            'both off' => [false, false, 'sql'],
        ];
    }

    #[Test]
    #[DataProvider('unknownUserRouting')]
    public function unknownUserIsRoutedByTheLdapSettings(bool $enabled, bool $autoProvision, string $expected): void
    {
        $this->assertSame($expected, $this->authMethodOf('newcomer', $enabled, $autoProvision));
    }

    #[Test]
    public function aKnownSqlUserStaysOnSqlEvenWithAutoProvisioningOn(): void
    {
        $this->assertSame('sql', $this->authMethodOf('alice', true, true, ['alice', 'sql']));
    }

    #[Test]
    public function aKnownLdapUserKeepsTheStoredMethod(): void
    {
        $this->assertSame('ldap', $this->authMethodOf('bob', false, false, ['bob', 'ldap']));
    }

    #[Test]
    public function anUnknownUserIsNotLoggedAsAWarning(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())->method('warning');

        $this->authMethodOf('newcomer', true, true, null, $logger);
    }

    /**
     * @param array{0: string, 1: string}|null $row username and auth_method of the users row
     */
    private function authMethodOf(string $username, bool $ldapEnabled, bool $autoProvision, ?array $row = null, ?Logger $logger = null): string
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE users (username TEXT, auth_method TEXT)');
        if ($row !== null) {
            $db->prepare('INSERT INTO users (username, auth_method) VALUES (?, ?)')->execute($row);
        }

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn($section, $key, $default = null) => $section === 'ldap'
                ? ['enabled' => $ldapEnabled, 'auto_provision' => $autoProvision][$key] ?? $default
                : $default
        );

        $reflection = new ReflectionClass(SessionAuthenticator::class);
        $authenticator = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('db')->setValue($authenticator, $db);
        $reflection->getProperty('configManager')->setValue($authenticator, $config);
        $reflection->getParentClass()->getProperty('logger')->setValue($authenticator, $logger ?? $this->createMock(Logger::class));

        $_SESSION = [SessionKeys::USERLOGIN => $username];

        return $reflection->getMethod('getUserAuthMethod')->invoke($authenticator);
    }
}
