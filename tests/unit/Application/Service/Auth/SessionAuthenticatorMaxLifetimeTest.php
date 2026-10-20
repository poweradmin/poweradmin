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
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Auth\SessionAuthenticator;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\ArraySession;
use ReflectionMethod;
use TestHelpers\FakeConfiguration;

/**
 * interface.session_max_lifetime ends a session that long after sign-in, however
 * active it stays; 0 leaves only the idle timeout.
 */
#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorMaxLifetimeTest extends TestCase
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

    public function testAnActiveSessionPastItsMaximumLifetimeIsSignedOut(): void
    {
        $session = $this->signedInSession(['signed_in_at' => time() - 3601]);

        try {
            $this->authenticator($session, 3600)->authenticate();
            $this->fail('A session past its maximum lifetime must redirect to the login page');
        } catch (RequestHalted $halted) {
            $this->assertSame(RequestHalted::KIND_REDIRECT, $halted->kind);
        }

        $this->assertFalse($session->has(SessionKeys::USERID));
    }

    public function testTheFirstSignedInRequestStartsTheClock(): void
    {
        $session = $this->signedInSession();

        $this->assertFalse($this->lifetimeExceeded($this->authenticator($session, 3600)));
        $this->assertEqualsWithDelta(time(), $session->get(SessionKeys::SIGNED_IN_AT), 2);
    }

    public function testASessionWithinItsMaximumLifetimeContinues(): void
    {
        $session = $this->signedInSession(['signed_in_at' => time() - 3500]);

        $this->assertFalse($this->lifetimeExceeded($this->authenticator($session, 3600)));
    }

    public function testNoMaximumLifetimeLeavesOldSessionsAlone(): void
    {
        $session = $this->signedInSession(['signed_in_at' => time() - 86400 * 30]);

        $this->assertFalse($this->lifetimeExceeded($this->authenticator($session, 0)));
    }

    public function testASessionNotYetSignedInIsNotStamped(): void
    {
        $session = new ArraySession([SessionKeys::PENDING_USERID => 1]);

        $this->assertFalse($this->lifetimeExceeded($this->authenticator($session, 3600)));
        $this->assertFalse($session->has(SessionKeys::SIGNED_IN_AT));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function signedInSession(array $extra = []): ArraySession
    {
        return new ArraySession(array_merge([
            SessionKeys::USERID => 1,
            SessionKeys::USERLOGIN => 'alice',
            SessionKeys::LASTMOD => time(),
        ], $extra));
    }

    private function authenticator(ArraySession $session, int $maxLifetime): SessionAuthenticator
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec((string)file_get_contents(dirname(__DIR__, 5) . '/sql/poweradmin-sqlite-db-structure.sql'));

        $config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'security' => ['session_key' => 'unit-test-session-key'],
            'interface' => ['session_timeout' => 1800, 'session_max_lifetime' => $maxLifetime],
        ]);

        return new SessionAuthenticator($db, $config, new Request([], [], ['REQUEST_METHOD' => 'GET']), $session);
    }

    private function lifetimeExceeded(SessionAuthenticator $authenticator): bool
    {
        return (new ReflectionMethod($authenticator, 'sessionLifetimeExceeded'))->invoke($authenticator);
    }
}
