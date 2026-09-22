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

namespace Poweradmin\Tests\Unit\Infrastructure\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Session\SessionActor;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * The session actor answers exactly what UserContextService reads from the
 * same session keys, so the two views of the web user never disagree.
 */
#[CoversClass(SessionActor::class)]
class SessionActorTest extends TestCase
{
    private ArraySession $session;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
    }

    protected function tearDown(): void
    {
        $this->session = new ArraySession();
    }

    public function testNobodyWhenTheSessionIsEmpty(): void
    {
        $actor = new SessionActor($this->session);

        $this->assertNull($actor->userId());
        $this->assertNull($actor->username());
    }

    public function testMatchesUserContextServiceForTheSessionUser(): void
    {
        $this->session->set('userid', 7);
        $this->session->set('userlogin', 'web-alice');
        $actor = new SessionActor($this->session);
        $context = new UserContextService($this->session);

        $this->assertSame($context->getLoggedInUserId(), $actor->userId());
        $this->assertSame($context->getLoggedInUsername(), $actor->username());
        $this->assertSame(7, $actor->userId());
    }
}
