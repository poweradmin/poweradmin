<?php

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\UserContextService;

class UserContextServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        UserContextService::clearApiUserContext();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        UserContextService::clearApiUserContext();
    }

    public function testReturnsNullWhenNeitherSessionNorApiContextIsSet(): void
    {
        $service = new UserContextService();
        $this->assertNull($service->getLoggedInUserId());
        $this->assertNull($service->getLoggedInUsername());
        $this->assertFalse($service->isAuthenticated());
    }

    public function testFallsBackToApiContextWhenSessionIsEmpty(): void
    {
        UserContextService::setApiUserContext(42, 'api-bot');

        $service = new UserContextService();
        $this->assertSame(42, $service->getLoggedInUserId());
        $this->assertSame('api-bot', $service->getLoggedInUsername());
        $this->assertTrue($service->isAuthenticated());
    }

    public function testSessionTakesPrecedenceOverApiContext(): void
    {
        $_SESSION['userid'] = 7;
        $_SESSION['userlogin'] = 'web-alice';
        UserContextService::setApiUserContext(99, 'api-bob');

        $service = new UserContextService();
        $this->assertSame(7, $service->getLoggedInUserId());
        $this->assertSame('web-alice', $service->getLoggedInUsername());
    }

    public function testClearApiUserContextRevertsToNull(): void
    {
        UserContextService::setApiUserContext(5, 'api-user');
        UserContextService::clearApiUserContext();

        $service = new UserContextService();
        $this->assertNull($service->getLoggedInUserId());
        $this->assertNull($service->getLoggedInUsername());
    }

    public function testSetApiUserContextRejectsZeroOrNegativeIds(): void
    {
        UserContextService::setApiUserContext(0, 'no-one');

        $service = new UserContextService();
        $this->assertNull($service->getLoggedInUserId(), 'user id 0 must not authenticate');
        $this->assertFalse($service->isAuthenticated());
    }

    public function testSetApiUserContextNormalizesEmptyUsername(): void
    {
        UserContextService::setApiUserContext(11, '');

        $service = new UserContextService();
        $this->assertSame(11, $service->getLoggedInUserId());
        $this->assertNull($service->getLoggedInUsername());
    }
}
