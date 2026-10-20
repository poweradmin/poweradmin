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

namespace Poweradmin\Tests\Unit\Domain\Service\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\UserAuthenticationService;
use Poweradmin\Application\Service\User\PasswordPolicyService;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\User\CreateUserCommand;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\User\UserProfileAssembler;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;

/**
 * Plus-addressed and other RFC 5322 local parts are accepted on add and edit (#1674);
 * a malformed address is still refused.
 */
#[CoversClass(UserManagementService::class)]
class UserManagementServiceEmailFormatTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userRepository->method('getUserByUsername')->willReturn(null);
        $this->userRepository->method('getUserByEmail')->willReturn(null);
        $this->userRepository->method('permissionTemplateExists')->willReturn(true);
        $this->userRepository->method('getUserById')->willReturn(['id' => 7, 'username' => 'alice', 'email' => 'alice@example.com', 'auth_method' => 'sql']);
        $this->userRepository->method('createUser')->willReturn(42);
        $this->userRepository->method('updateUser')->willReturn(true);
    }

    private function service(): UserManagementService
    {
        $policy = $this->createMock(PasswordPolicyService::class);
        $policy->method('validatePassword')->willReturn([]);
        $hasher = $this->createMock(UserAuthenticationService::class);
        $hasher->method('hashPassword')->willReturn('$hash');

        return new UserManagementService(
            $this->userRepository,
            $permissions = $this->createMock(PermissionService::class),
            new UserProfileAssembler($permissions, $this->createMock(UserGroupRepositoryInterface::class)),
            $hasher,
            $policy,
            false,
            $this->createMock(DomainManagerInterface::class),
            $this->createMock(ZoneManagementService::class)
        );
    }

    public static function acceptedAddresses(): array
    {
        return [
            'plus addressing' => ['user+tag@example.com'],
            'dotted local part' => ['user.name@example.com'],
            'subdomain' => ['user@sub.example.com'],
        ];
    }

    #[DataProvider('acceptedAddresses')]
    public function testAddAcceptsAddress(string $email): void
    {
        $result = $this->service()->createUser(new CreateUserCommand('bob', 'Secret123!', email: $email, permissionTemplateId: 3));

        $this->assertTrue($result['success'], (string)($result['message'] ?? ''));
    }

    #[DataProvider('acceptedAddresses')]
    public function testEditAcceptsAddress(string $email): void
    {
        $result = $this->service()->updateUser(7, new UpdateUserCommand(email: $email));

        $this->assertTrue($result['success'], (string)($result['message'] ?? ''));
    }

    public function testAMalformedAddressIsStillRefused(): void
    {
        $add = $this->service()->createUser(new CreateUserCommand('bob', 'Secret123!', email: 'us er@example.com', permissionTemplateId: 3));
        $edit = $this->service()->updateUser(7, new UpdateUserCommand(email: 'user@'));

        $this->assertSame(UserManagementService::ERR_INVALID_EMAIL, $add['code'] ?? null);
        $this->assertSame(UserManagementService::ERR_INVALID_EMAIL, $edit['code'] ?? null);
    }
}
