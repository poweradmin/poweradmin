<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Auth;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Session\ArraySession;

class UserContextServiceTest extends TestCase
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

    public function testReturnsNullWhenTheSessionHasNoUser(): void
    {
        $service = new UserContextService($this->session);
        $this->assertNull($service->getLoggedInUserId());
        $this->assertNull($service->getLoggedInUsername());
        $this->assertFalse($service->isAuthenticated());
    }

    public function testReadsTheSessionUser(): void
    {
        $this->session->set('userid', 7);
        $this->session->set('userlogin', 'web-alice');

        $service = new UserContextService($this->session);
        $this->assertSame(7, $service->getLoggedInUserId());
        $this->assertSame('web-alice', $service->getLoggedInUsername());
        $this->assertTrue($service->isAuthenticated());
    }

    public function testUserIdZeroDoesNotCountAsAuthenticated(): void
    {
        $this->session->set('userid', 0);

        $this->assertFalse((new UserContextService($this->session))->isAuthenticated());
    }
}
