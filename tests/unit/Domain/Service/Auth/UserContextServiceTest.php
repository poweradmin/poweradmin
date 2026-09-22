<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Auth;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Session\PhpSession;

class UserContextServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testReturnsNullWhenTheSessionHasNoUser(): void
    {
        $service = new UserContextService(new PhpSession());
        $this->assertNull($service->getLoggedInUserId());
        $this->assertNull($service->getLoggedInUsername());
        $this->assertFalse($service->isAuthenticated());
    }

    public function testReadsTheSessionUser(): void
    {
        $_SESSION['userid'] = 7;
        $_SESSION['userlogin'] = 'web-alice';

        $service = new UserContextService(new PhpSession());
        $this->assertSame(7, $service->getLoggedInUserId());
        $this->assertSame('web-alice', $service->getLoggedInUsername());
        $this->assertTrue($service->isAuthenticated());
    }

    public function testUserIdZeroDoesNotCountAsAuthenticated(): void
    {
        $_SESSION['userid'] = 0;

        $this->assertFalse((new UserContextService(new PhpSession()))->isAuthenticated());
    }
}
