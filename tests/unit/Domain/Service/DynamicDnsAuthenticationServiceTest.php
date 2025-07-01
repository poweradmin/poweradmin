<?php

namespace unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Repository\DynamicDnsRepositoryInterface;
use Poweradmin\Domain\Service\DynamicDnsAuthenticationService;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Poweradmin\Domain\Model\User;

class DynamicDnsAuthenticationServiceTest extends TestCase
{
    private DynamicDnsAuthenticationService $authService;
    private DynamicDnsRepositoryInterface $mockRepository;
    /** @var UserAuthenticationService&\PHPUnit\Framework\MockObject\MockObject */
    private $mockUserAuthService;

    protected function setUp(): void
    {
        $this->mockRepository = $this->createMock(DynamicDnsRepositoryInterface::class);
        $this->mockUserAuthService = $this->createMock(UserAuthenticationService::class);
        $this->authService = new DynamicDnsAuthenticationService($this->mockRepository, $this->mockUserAuthService);
    }

    public function testAuthenticateUserSuccess(): void
    {
        $request = new DynamicDnsRequest(
            'testuser',
            'testpass',
            'example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $user = new User(123, 'hashedpassword', false);

        $this->mockRepository->expects($this->once())
            ->method('findUserByUsernameWithDynamicDnsPermissions')
            ->with('testuser')
            ->willReturn($user);

        $this->mockUserAuthService->expects($this->once())
            ->method('verifyPassword')
            ->with('testpass', 'hashedpassword')
            ->willReturn(true);

        $result = $this->authService->authenticateUser($request);

        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals(123, $result->getId());
        $this->assertEquals('hashedpassword', $result->getPassword());
        $this->assertFalse($result->isLdapUser());
    }

    public function testAuthenticateUserNoUsername(): void
    {
        $request = new DynamicDnsRequest(
            '',
            'testpass',
            'example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $user = $this->authService->authenticateUser($request);
        $this->assertNull($user);
    }

    public function testAuthenticateUserNotFound(): void
    {
        $request = new DynamicDnsRequest(
            'nonexistent',
            'testpass',
            'example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $this->mockRepository->expects($this->once())
            ->method('findUserByUsernameWithDynamicDnsPermissions')
            ->with('nonexistent')
            ->willReturn(null);

        $user = $this->authService->authenticateUser($request);
        $this->assertNull($user);
    }

    public function testAuthenticateUserWrongPassword(): void
    {
        $request = new DynamicDnsRequest(
            'testuser',
            'wrongpass',
            'example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $user = new User(123, 'hashedpassword', false);

        $this->mockRepository->expects($this->once())
            ->method('findUserByUsernameWithDynamicDnsPermissions')
            ->with('testuser')
            ->willReturn($user);

        $this->mockUserAuthService->expects($this->once())
            ->method('verifyPassword')
            ->with('wrongpass', 'hashedpassword')
            ->willReturn(false);

        $result = $this->authService->authenticateUser($request);
        $this->assertNull($result);
    }

    public function testGetUserZones(): void
    {
        $user = new User(123, 'hashedpassword', false);

        $this->mockRepository->expects($this->once())
            ->method('getUserZones')
            ->with($user)
            ->willReturn([1, 2]);

        $zones = $this->authService->getUserZones($user);
        $this->assertEquals([1, 2], $zones);
    }

    public function testGetUserZonesEmpty(): void
    {
        $user = new User(123, 'hashedpassword', false);

        $this->mockRepository->expects($this->once())
            ->method('getUserZones')
            ->with($user)
            ->willReturn([]);

        $zones = $this->authService->getUserZones($user);
        $this->assertEquals([], $zones);
    }

    public function testUserCanUpdateZone(): void
    {
        $user = new User(123, 'hashedpassword', false);

        $this->mockRepository->expects($this->exactly(3))
            ->method('getUserZones')
            ->with($user)
            ->willReturn([1, 2]);

        $this->assertTrue($this->authService->userCanUpdateZone($user, 1));
        $this->assertTrue($this->authService->userCanUpdateZone($user, 2));
        $this->assertFalse($this->authService->userCanUpdateZone($user, 3));
    }

    public function testAuthenticateUserWithLdap(): void
    {
        $request = new DynamicDnsRequest(
            'ldapuser',
            'testpass',
            'example.com',
            '192.168.1.1',
            '',
            false,
            'TestAgent/1.0'
        );

        $user = new User(456, 'hashedpassword', true);

        $this->mockRepository->expects($this->once())
            ->method('findUserByUsernameWithDynamicDnsPermissions')
            ->with('ldapuser')
            ->willReturn($user);

        $this->mockUserAuthService->expects($this->once())
            ->method('verifyPassword')
            ->with('testpass', 'hashedpassword')
            ->willReturn(true);

        $result = $this->authService->authenticateUser($request);

        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals(456, $result->getId());
        $this->assertTrue($result->isLdapUser());
    }
}
