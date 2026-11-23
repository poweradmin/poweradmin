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
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserMfaRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\User\UserProfileAssembler;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;

/**
 * Email codes go to users.email, so an address set by another user must not keep
 * receiving them: the email factor is switched off and the owner enrols again.
 */
#[CoversClass(UserManagementService::class)]
class UserManagementServiceEmailMfaResetTest extends TestCase
{
    private const TARGET_ID = 7;
    private const ACTOR_ID = 3;

    private UserRepositoryInterface&MockObject $userRepository;
    private UserMfaRepositoryInterface&MockObject $mfaRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('getUserByEmail')->willReturn(null);
        $this->userRepository->method('getUserById')->willReturn(
            ['id' => self::TARGET_ID, 'username' => 'alice', 'fullname' => 'Alice', 'email' => 'alice@example.com', 'auth_method' => 'sql']
        );
        $this->userRepository->method('updateUser')->willReturn(true);
        $this->mfaRepository = $this->createMock(UserMfaRepositoryInterface::class);
    }

    private function service(): UserManagementService
    {
        $permissions = $this->createMock(PermissionService::class);

        return new UserManagementService(
            $this->userRepository,
            $permissions,
            new UserProfileAssembler($permissions, $this->createMock(UserGroupRepositoryInterface::class)),
            $this->createMock(UserAuthenticationService::class),
            $this->createMock(PasswordPolicyService::class),
            false,
            $this->createMock(DomainManagerInterface::class),
            $this->createMock(ZoneManagementService::class),
            mfaRepository: $this->mfaRepository
        );
    }

    private function enrolled(string $type): UserMfa
    {
        return UserMfa::create(self::TARGET_ID, true, 'SECRET', null, $type);
    }

    public function testForeignEmailChangeSwitchesOffEmailMfa(): void
    {
        $this->mfaRepository->method('findByUserId')->willReturn($this->enrolled(UserMfa::TYPE_EMAIL));
        $this->mfaRepository->expects($this->once())->method('save')
            ->with($this->callback(static fn(UserMfa $mfa): bool => !$mfa->isEnabled() && $mfa->getSecret() === null));

        $result = $this->service()->updateUser(self::TARGET_ID, new UpdateUserCommand(email: 'actor@example.com'), self::ACTOR_ID);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['mfa_reset']);
    }

    public function testOwnEmailChangeKeepsEmailMfa(): void
    {
        $this->mfaRepository->method('findByUserId')->willReturn($this->enrolled(UserMfa::TYPE_EMAIL));
        $this->mfaRepository->expects($this->never())->method('save');

        $result = $this->service()->updateUser(self::TARGET_ID, new UpdateUserCommand(email: 'new@example.com'), self::TARGET_ID);

        $this->assertFalse($result['mfa_reset']);
    }

    public function testForeignEmailChangeLeavesTotpAlone(): void
    {
        $this->mfaRepository->method('findByUserId')->willReturn($this->enrolled(UserMfa::TYPE_APP));
        $this->mfaRepository->expects($this->never())->method('save');

        $result = $this->service()->updateUser(self::TARGET_ID, new UpdateUserCommand(email: 'actor@example.com'), self::ACTOR_ID);

        $this->assertFalse($result['mfa_reset']);
    }

    public function testForeignEditWithoutEmailChangeLeavesEmailMfaAlone(): void
    {
        $this->mfaRepository->method('findByUserId')->willReturn($this->enrolled(UserMfa::TYPE_EMAIL));
        $this->mfaRepository->expects($this->never())->method('save');

        $this->service()->updateUser(self::TARGET_ID, new UpdateUserCommand(fullname: 'Alice B'), self::ACTOR_ID);
        $this->service()->updateUser(self::TARGET_ID, new UpdateUserCommand(email: 'ALICE@example.com'), self::ACTOR_ID);
    }

    public function testNotEnrolledOrAlreadyOffIsANoOp(): void
    {
        $this->mfaRepository->method('findByUserId')->willReturnOnConsecutiveCalls(
            null,
            UserMfa::create(self::TARGET_ID, false, null, null, UserMfa::TYPE_EMAIL)
        );
        $this->mfaRepository->expects($this->never())->method('save');

        $service = $this->service();
        $this->assertFalse($service->updateUser(self::TARGET_ID, new UpdateUserCommand(email: 'a@example.com'), self::ACTOR_ID)['mfa_reset']);
        $this->assertFalse($service->updateUser(self::TARGET_ID, new UpdateUserCommand(email: 'b@example.com'), self::ACTOR_ID)['mfa_reset']);
    }
}
