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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\ApiKeyActor;
use Poweradmin\Application\Service\Auth\RequestActor;
use Poweradmin\Infrastructure\Session\SessionActor;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * The API actor normalises what the key lookup hands it the way the old
 * request-scoped seeding did: id 0 and an empty username read as nobody.
 */
#[CoversClass(ApiKeyActor::class)]
#[CoversClass(RequestActor::class)]
class ApiKeyActorTest extends TestCase
{
    private ArraySession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new ArraySession();
    }

    public function testNamesTheKeyOwner(): void
    {
        $actor = new ApiKeyActor(42, 'api-bot');

        $this->assertSame(42, $actor->userId());
        $this->assertSame('api-bot', $actor->username());
    }

    public function testUserIdZeroIsNobody(): void
    {
        $this->assertNull((new ApiKeyActor(0, 'no-one'))->userId());
    }

    public function testEmptyUsernameReadsAsNull(): void
    {
        $actor = new ApiKeyActor(11, '');

        $this->assertSame(11, $actor->userId());
        $this->assertNull($actor->username());
    }

    public function testTheApiActorIgnoresABrowserSessionRidingAlong(): void
    {
        $this->session->set('userid', 7);
        $this->session->set('userlogin', 'web-alice');

        $actor = new ApiKeyActor(99, 'api-bob');

        $this->assertSame(99, $actor->userId());
        $this->assertSame('api-bob', $actor->username());
    }

    public function testRequestActorFollowsTheLatestBinding(): void
    {
        $this->session->set('userid', 7);
        $this->session->set('userlogin', 'web-alice');
        $actor = new RequestActor(new SessionActor($this->session));
        $this->assertSame(7, $actor->userId());
        $this->assertSame('web-alice', $actor->username());

        $actor->bind(new ApiKeyActor(99, 'api-bob'));

        $this->assertSame(99, $actor->userId());
        $this->assertSame('api-bob', $actor->username());
    }
}
