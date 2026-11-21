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

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Auth\ExternalSessionGuard;
use Poweradmin\Application\Service\Auth\SessionAuthenticator;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;
use TestHelpers\FakeConfiguration;

/**
 * Switching OIDC or SAML off, or removing the provider a session signed in through,
 * ends the sessions that came from it instead of leaving them alive until idle timeout.
 */
#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorSsoProviderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /**
     * @return array<string, array{string, array<string, mixed>, ?string, bool}>
     */
    public static function cases(): array
    {
        $oidcOk = ['enabled' => true, 'providers' => ['kc' => ['client_id' => 'a', 'client_secret' => 'b']]];
        $samlOk = ['enabled' => true, 'providers' => ['idp' => ['enabled' => true]]];

        return [
            'oidc global off' => ['oidc', ['enabled' => false] + $oidcOk, 'kc', true],
            'oidc provider removed' => ['oidc', ['providers' => []] + $oidcOk, 'kc', true],
            'oidc provider lost secret' => ['oidc', ['providers' => ['kc' => ['client_id' => 'a', 'client_secret' => '']]] + $oidcOk, 'kc', true],
            'oidc provider intact' => ['oidc', $oidcOk, 'kc', false],
            'oidc no provider key, global on' => ['oidc', ['providers' => []] + $oidcOk, null, false],
            'oidc no provider key, global off' => ['oidc', ['enabled' => false] + $oidcOk, null, true],
            'saml global off' => ['saml', ['enabled' => false] + $samlOk, 'idp', true],
            'saml provider removed' => ['saml', ['providers' => []] + $samlOk, 'idp', true],
            'saml provider disabled' => ['saml', ['providers' => ['idp' => ['enabled' => false]]] + $samlOk, 'idp', true],
            'saml provider enabled zero' => ['saml', ['providers' => ['idp' => ['enabled' => 0]]] + $samlOk, 'idp', true],
            'saml provider intact' => ['saml', $samlOk, 'idp', false],
            'saml no provider key, global on' => ['saml', ['providers' => []] + $samlOk, null, false],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('cases')]
    public function testSessionEndsWhenProviderIsSwitchedOff(string $method, array $settings, ?string $providerId, bool $ended): void
    {
        $config = $this->config([$method => $settings]);
        $extra = [SessionKeys::SSO_INSTANCE => ExternalSessionGuard::instanceFingerprint($config)];
        if ($providerId !== null) {
            $extra[$method === 'oidc' ? SessionKeys::OIDC_PROVIDER : SessionKeys::SAML_PROVIDER] = $providerId;
        }
        $session = new ArraySession(array_merge([
            SessionKeys::USERID => 1,
            SessionKeys::USERLOGIN => 'alice',
            SessionKeys::LASTMOD => time(),
            SessionKeys::AUTH_METHOD_USED => $method,
        ], $extra));

        $this->assertSame($ended, $this->halts($config, $session));
        $this->assertSame(!$ended, $session->has(SessionKeys::USERID));
        if ($ended) {
            $this->assertSame('Session expired, please login again.', $session->get(SessionKeys::LOGIN_MESSAGE));
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function config(array $extra): FakeConfiguration
    {
        return new FakeConfiguration(array_merge([
            'database' => ['type' => 'mysql', 'host' => 'db', 'port' => '3306', 'name' => 'powerdns', 'file' => '', 'pdns_db_name' => ''],
            'security' => ['session_key' => 'unit-test-session-key'],
            'interface' => ['session_timeout' => 1800, 'session_max_lifetime' => 0],
        ], $extra));
    }

    private function halts(FakeConfiguration $config, ArraySession $session): bool
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec((string)file_get_contents(dirname(__DIR__, 5) . '/sql/poweradmin-sqlite-db-structure.sql'));
        $db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES (1, 'alice', 'x', 'Alice', 'a@example.com', '', 1, 1, 0)");

        $authenticator = new SessionAuthenticator($db, $config, new Request([], [], ['REQUEST_METHOD' => 'GET']), $session);

        try {
            $authenticator->authenticate();
        } catch (RequestHalted) {
            return true;
        }

        return false;
    }
}
