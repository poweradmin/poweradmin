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

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\ExternalIdentityRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\ValueObject\UserInfoInterface;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionClass;

/**
 * A new auto-provisioned account is created even when its address is unusable, but the address is
 * blanked so two accounts never share one: another account holds it, or the claim is unverified
 * while require_verified_email is on.
 */
#[CoversClass(UserProvisioningService::class)]
class UserProvisioningServiceEmailTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $created = [];

    /** @return array<string, array{string}> */
    public static function methods(): array
    {
        return [
            'oidc' => [UserProvisioningService::AUTH_METHOD_OIDC],
            'saml' => [UserProvisioningService::AUTH_METHOD_SAML],
            'ldap' => [UserProvisioningService::AUTH_METHOD_LDAP],
        ];
    }

    #[DataProvider('methods')]
    public function testAnAddressAnotherAccountHoldsIsBlanked(string $method): void
    {
        $this->assertSame(55, $this->provision($method, true, [], false));
        $this->assertSame('', $this->created[0]['email']);
    }

    #[DataProvider('methods')]
    public function testAFreeAddressIsKept(string $method): void
    {
        $this->assertSame(55, $this->provision($method, false, [], false));
        $this->assertSame('Bob@Example.com', $this->created[0]['email']);
    }

    #[DataProvider('methods')]
    public function testAnUnverifiedAddressIsBlankedWhenVerificationIsRequired(string $method): void
    {
        $this->assertSame(55, $this->provision($method, false, ['email_verified' => false], true));
        $this->assertSame('', $this->created[0]['email']);
    }

    public function testAVerifiedAddressIsKeptWhenVerificationIsRequired(): void
    {
        $this->assertSame(55, $this->provision(UserProvisioningService::AUTH_METHOD_OIDC, false, ['email_verified' => true], true));
        $this->assertSame('Bob@Example.com', $this->created[0]['email']);
    }

    /** @param array<string, mixed> $raw */
    private function provision(string $method, bool $emailTaken, array $raw, bool $requireVerified): ?int
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('templateGrantsUberuser')->willReturn(false);
        $repository->method('findPermissionTemplateIdByName')->willReturn(1);
        $repository->method('getUserByUsername')->willReturn(null);
        $repository->method('emailUsedByOtherUser')->willReturnCallback(
            fn(int $userId, string $email): bool => $emailTaken && $userId === 0 && $email === 'Bob@Example.com'
        );
        $repository->method('createProvisionedUser')->willReturnCallback(function (array $data): int {
            $this->created[] = $data;
            return 55;
        });

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => $group === $method && $key === 'default_permission_template' ? 'Administrator' : $default
        );
        $config->method('getGroup')->willReturn(['require_verified_email' => $requireVerified]);

        $service = new UserProvisioningService(
            $config,
            $this->createMock(Logger::class),
            $repository,
            $this->createMock(ExternalIdentityRepositoryInterface::class),
            $this->createMock(UserGroupLookupInterface::class),
            $this->createMock(UserGroupMemberRepositoryInterface::class)
        );

        $userInfo = $this->createMock(UserInfoInterface::class);
        $userInfo->method('getUsername')->willReturn('bob');
        $userInfo->method('getEmail')->willReturn('Bob@Example.com');
        $userInfo->method('getDisplayName')->willReturn('Bob');
        $userInfo->method('getFullName')->willReturn('Bob');
        $userInfo->method('getGroups')->willReturn([]);
        $userInfo->method('getRawData')->willReturn($raw);

        $create = (new ReflectionClass(UserProvisioningService::class))->getMethod('createNewUser');

        return $create->invoke($service, $userInfo, 'provider', $method);
    }
}
