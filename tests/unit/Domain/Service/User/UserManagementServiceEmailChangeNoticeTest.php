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
 */

namespace Poweradmin\Tests\Unit\Domain\Service\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\UserAuthenticationService;
use Poweradmin\Application\Service\User\PasswordPolicyService;
use Poweradmin\Domain\Port\EmailChangeNotifierInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\User\UserProfileAssembler;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;

/**
 * The previous address is told when an account's email changes, and a failing
 * mailer never undoes the edit.
 */
#[CoversClass(UserManagementService::class)]
class UserManagementServiceEmailChangeNoticeTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('getUserByEmail')->willReturn(null);
        $this->userRepository->method('getUserById')->willReturn(
            ['id' => 7, 'username' => 'alice', 'fullname' => 'Alice', 'email' => 'alice@example.com', 'auth_method' => 'sql']
        );
        $this->userRepository->method('updateUser')->willReturn(true);
    }

    private function service(?EmailChangeNotifierInterface $notifier): UserManagementService
    {
        $policy = $this->createMock(PasswordPolicyService::class);
        $hasher = $this->createMock(UserAuthenticationService::class);

        return new UserManagementService(
            $this->userRepository,
            $permissions = $this->createMock(PermissionService::class),
            new UserProfileAssembler($permissions, $this->createMock(UserGroupRepositoryInterface::class)),
            $hasher,
            $policy,
            false,
            $this->createMock(DomainManagerInterface::class),
            $this->createMock(ZoneManagementService::class),
            emailChangeNotifier: $notifier
        );
    }

    public function testChangedEmailNotifiesOldAddress(): void
    {
        $notifier = $this->createMock(EmailChangeNotifierInterface::class);
        $notifier->expects($this->once())->method('notifyEmailChanged')
            ->with('alice@example.com', 'new@example.com', 'Alice')
            ->willReturn(true);

        $result = $this->service($notifier)->updateUser(7, new UpdateUserCommand(email: 'new@example.com'));

        $this->assertTrue($result['success']);
    }

    public function testUnchangedOrAbsentEmailSendsNoNotice(): void
    {
        $notifier = $this->createMock(EmailChangeNotifierInterface::class);
        $notifier->expects($this->never())->method('notifyEmailChanged');

        $service = $this->service($notifier);
        $service->updateUser(7, new UpdateUserCommand(email: 'Alice@Example.com'));
        $service->updateUser(7, new UpdateUserCommand(fullname: 'Alice B'));
    }

    public function testMailerFailureDoesNotBlockTheEdit(): void
    {
        $notifier = $this->createMock(EmailChangeNotifierInterface::class);
        $notifier->method('notifyEmailChanged')->willThrowException(new \RuntimeException('smtp down'));

        $result = $this->service($notifier)->updateUser(7, new UpdateUserCommand(email: 'new@example.com'));

        $this->assertTrue($result['success']);
    }
}
