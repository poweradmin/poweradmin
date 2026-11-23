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

use DateTime;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Auth\ExternalSessionGuard;
use Poweradmin\Application\Service\Auth\SessionAuthenticator;
use Poweradmin\Domain\Enum\MfaSessionState;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use Poweradmin\Infrastructure\Session\ArraySession;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use Poweradmin\Infrastructure\Session\MfaSessionManager;
use TestHelpers\FakeConfiguration;

/**
 * A single sign-on session is not re-checked against a password, so it must still be challenged
 * again when the user's second factor was enrolled afresh after the session verified it.
 */
#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorSsoMfaBindingTest extends TestCase
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

    /** @return array<string, array{string}> */
    public static function methods(): array
    {
        return ['oidc' => ['oidc'], 'saml' => ['saml'], 'remote_user' => ['remote_user']];
    }

    #[DataProvider('methods')]
    public function testASessionVerifiedUnderAnEarlierEnrolmentIsChallengedAgain(string $method): void
    {
        [$session, $halted] = $this->request($method, 'binding-from-before-the-reenrolment');

        $this->assertTrue($halted);
        $this->assertSame(MfaSessionState::PENDING, (new MfaSessionManager($session))->currentState());
        $this->assertSame(1, $session->get(SessionKeys::PENDING_USERID));
    }

    #[DataProvider('methods')]
    public function testASessionVerifiedUnderTheCurrentEnrolmentIsLeftAlone(string $method): void
    {
        [$session, $halted] = $this->request($method, null);

        $this->assertFalse($halted);
        $this->assertSame(MfaSessionState::VERIFIED, (new MfaSessionManager($session))->currentState());
        $this->assertFalse($session->has(SessionKeys::PENDING_USERID));
    }

    #[DataProvider('methods')]
    public function testALogoutRequestThatKeepsTheSessionDoesNotAdoptTheNewEnrolment(string $method): void
    {
        [$session, $halted] = $this->request($method, 'binding-from-before-the-reenrolment', true);

        $this->assertTrue($halted);
        $this->assertSame(MfaSessionState::PENDING, (new MfaSessionManager($session))->currentState());
    }

    /** @return array{ArraySession, bool} */
    private function request(string $method, ?string $verifiedUnder, bool $logoutRequestBetween = false): array
    {
        $config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'security' => ['session_key' => 'unit-test-session-key', 'mfa.enabled' => true],
            'interface' => ['session_timeout' => 1800, 'session_max_lifetime' => 0],
            $method => ['enabled' => true, 'providers' => ['p' => ['client_id' => 'a', 'client_secret' => 'b', 'enabled' => true]]],
        ]);
        $_SERVER['REMOTE_USER'] = 'alice';

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec((string)file_get_contents(dirname(__DIR__, 5) . '/sql/poweradmin-sqlite-db-structure.sql'));
        $db->exec("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES (1, 'alice', 'x', 'Alice', 'a@example.com', '', 1, 1, 0)");
        $db->exec("UPDATE users SET auth_method = '$method'");
        $repository = new DbUserMfaRepository($db, $config);
        $mfa = $repository->save(new UserMfa(0, 1, false, 'S', null, UserMfa::TYPE_APP, null, new DateTime('2020-01-01 00:00:00'), null));
        $mfa->enable();
        $current = $repository->save($mfa)->sessionBinding();

        $session = new ArraySession([
            SessionKeys::USERID => 1,
            SessionKeys::USERLOGIN => 'alice',
            SessionKeys::LASTMOD => time(),
            SessionKeys::AUTH_METHOD_USED => $method,
            SessionKeys::AUTH_USED => $method,
            SessionKeys::SSO_INSTANCE => ExternalSessionGuard::instanceFingerprint($config),
            SessionKeys::REMOTE_USER_IDENTITY => 'alice',
        ]);
        if ($method !== 'remote_user') {
            $session->set($method === 'oidc' ? SessionKeys::OIDC_PROVIDER : SessionKeys::SAML_PROVIDER, 'p');
        }
        (new MfaSessionManager($session))->setMfaVerified($verifiedUnder ?? $current);

        $halted = $this->authenticate($db, $config, $session);
        if ($logoutRequestBetween) {
            // What /mfa/verify?logout does for a web server session: it keeps the session and unsets these
            $session->remove(AuthFlowSessionKeys::MFA_REQUIRED);
            $session->remove(AuthFlowSessionKeys::MFA_STATE);
            $halted = $this->authenticate($db, $config, $session);
        }

        return [$session, $halted];
    }

    private function authenticate(PDO $db, FakeConfiguration $config, ArraySession $session): bool
    {
        $authenticator = new SessionAuthenticator($db, $config, new Request([], [], ['REQUEST_METHOD' => 'GET']), $session);
        try {
            $authenticator->authenticate();
        } catch (RequestHalted) {
            return true;
        }

        return false;
    }
}
