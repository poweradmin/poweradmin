<?php

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\LoginAttemptService;
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
            ->willReturn([1 => 'a.example.com', 2 => 'b.example.com']);

        $zones = $this->authService->getUserZones($user);
        $this->assertEquals([1 => 'a.example.com', 2 => 'b.example.com'], $zones);
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
            ->willReturn([1 => 'a.example.com', 2 => 'b.example.com']);

        $this->assertTrue($this->authService->userCanUpdateZone($user, 1));
        $this->assertTrue($this->authService->userCanUpdateZone($user, 2));
        $this->assertFalse($this->authService->userCanUpdateZone($user, 3));
    }

    public function testAuthenticateUserRefusedWhenAccountLocked(): void
    {
        $loginAttempts = $this->createMock(LoginAttemptService::class);
        $loginAttempts->expects($this->once())
            ->method('isAccountLocked')
            ->with('testuser', '198.51.100.1')
            ->willReturn(true);

        $this->mockRepository->expects($this->never())->method('findUserByUsernameWithDynamicDnsPermissions');
        $this->mockUserAuthService->expects($this->never())->method('verifyPassword');
        $loginAttempts->expects($this->never())->method('recordAttempt');

        $service = new DynamicDnsAuthenticationService(
            $this->mockRepository,
            $this->mockUserAuthService,
            $loginAttempts
        );

        $request = new DynamicDnsRequest('testuser', 'testpass', 'example.com', '192.168.1.1', '', false, 'TestAgent/1.0');
        $this->assertNull($service->authenticateUser($request, '198.51.100.1'));
    }

    public function testAuthenticateUserRecordsSuccessfulAttempt(): void
    {
        $loginAttempts = $this->createMock(LoginAttemptService::class);
        $loginAttempts->method('isAccountLocked')->willReturn(false);
        $loginAttempts->expects($this->once())
            ->method('recordAttempt')
            ->with('testuser', '198.51.100.2', true);

        $user = new User(123, 'hashedpassword', false);
        $this->mockRepository->method('findUserByUsernameWithDynamicDnsPermissions')->willReturn($user);
        $this->mockUserAuthService->method('verifyPassword')->willReturn(true);

        $service = new DynamicDnsAuthenticationService(
            $this->mockRepository,
            $this->mockUserAuthService,
            $loginAttempts
        );

        $request = new DynamicDnsRequest('testuser', 'testpass', 'example.com', '192.168.1.1', '', false, 'TestAgent/1.0');
        $this->assertInstanceOf(User::class, $service->authenticateUser($request, '198.51.100.2'));
    }

    public function testAuthenticateUserRecordsFailedAttempt(): void
    {
        $loginAttempts = $this->createMock(LoginAttemptService::class);
        $loginAttempts->method('isAccountLocked')->willReturn(false);
        $loginAttempts->expects($this->once())
            ->method('recordAttempt')
            ->with('testuser', '198.51.100.3', false);

        $user = new User(123, 'hashedpassword', false);
        $this->mockRepository->method('findUserByUsernameWithDynamicDnsPermissions')->willReturn($user);
        $this->mockUserAuthService->method('verifyPassword')->willReturn(false);

        $service = new DynamicDnsAuthenticationService(
            $this->mockRepository,
            $this->mockUserAuthService,
            $loginAttempts
        );

        $request = new DynamicDnsRequest('testuser', 'wrongpass', 'example.com', '192.168.1.1', '', false, 'TestAgent/1.0');
        $this->assertNull($service->authenticateUser($request, '198.51.100.3'));
    }

    public function testAuthenticateUserDoesNotRecordAttemptForUnknownUsername(): void
    {
        // Unknown-username requests intentionally skip recordAttempt: the lockout
        // tracker only counts attempts against existing user_ids, so a recorded
        // attempt with user_id=null is dead weight. Brute force across unknown
        // usernames is acknowledged as a documented LOW-severity limitation.
        $loginAttempts = $this->createMock(LoginAttemptService::class);
        $loginAttempts->method('isAccountLocked')->willReturn(false);
        $loginAttempts->expects($this->never())->method('recordAttempt');

        $this->mockRepository->method('findUserByUsernameWithDynamicDnsPermissions')->willReturn(null);

        $service = new DynamicDnsAuthenticationService(
            $this->mockRepository,
            $this->mockUserAuthService,
            $loginAttempts
        );

        $request = new DynamicDnsRequest('nonexistent', 'testpass', 'example.com', '192.168.1.1', '', false, 'TestAgent/1.0');
        $this->assertNull($service->authenticateUser($request, '198.51.100.4'));
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
