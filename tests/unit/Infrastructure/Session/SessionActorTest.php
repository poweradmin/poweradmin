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
use Poweradmin\Infrastructure\Session\PhpSession;

/**
 * The session actor answers exactly what UserContextService reads from the
 * same session keys, so the two views of the web user never disagree.
 */
#[CoversClass(SessionActor::class)]
class SessionActorTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testNobodyWhenTheSessionIsEmpty(): void
    {
        $actor = new SessionActor(new PhpSession());

        $this->assertNull($actor->userId());
        $this->assertNull($actor->username());
    }

    public function testMatchesUserContextServiceForTheSessionUser(): void
    {
        $_SESSION['userid'] = 7;
        $_SESSION['userlogin'] = 'web-alice';
        $actor = new SessionActor(new PhpSession());
        $context = new UserContextService(new PhpSession());

        $this->assertSame($context->getLoggedInUserId(), $actor->userId());
        $this->assertSame($context->getLoggedInUsername(), $actor->username());
        $this->assertSame(7, $actor->userId());
    }
}
