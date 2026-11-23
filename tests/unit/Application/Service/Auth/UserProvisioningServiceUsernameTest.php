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
 * Auto-provisioned usernames follow the create and rename rule: no surrounding whitespace and
 * no control characters. LDAP and web server accounts sign in by exact name, so they are refused;
 * single sign-on accounts are found by the provider's subject, so the name is cleaned.
 */
#[CoversClass(UserProvisioningService::class)]
class UserProvisioningServiceUsernameTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $created = [];

    /** @return array<string, array{string}> */
    public static function exactNameMethods(): array
    {
        return ['ldap' => [UserProvisioningService::AUTH_METHOD_LDAP], 'remote_user' => [UserProvisioningService::AUTH_METHOD_REMOTE_USER]];
    }

    #[DataProvider('exactNameMethods')]
    public function testExactNameSignInsAreRefusedWhenTheNameIsNotClean(string $method): void
    {
        foreach (["bob ", " bob", "bo\nb"] as $name) {
            $this->assertNull($this->provision($name, $method), "provisioned '$name'");
        }
        $this->assertSame([], $this->created);
    }

    #[DataProvider('exactNameMethods')]
    public function testExactNameSignInsKeepACleanName(string $method): void
    {
        $this->assertSame(55, $this->provision('bob smith', $method));
        $this->assertSame('bob smith', $this->created[0]['username']);
    }

    public function testSingleSignOnNamesAreCleaned(): void
    {
        $this->assertSame(55, $this->provision(" bob\t", UserProvisioningService::AUTH_METHOD_OIDC));
        $this->assertSame('bob', $this->created[0]['username']);
    }

    public function testASingleSignOnNameThatIsEmptyOnceCleanedIsRefused(): void
    {
        $this->assertNull($this->provision(" \n ", UserProvisioningService::AUTH_METHOD_SAML));
        $this->assertSame([], $this->created);
    }

    private function provision(string $username, string $method): ?int
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('templateGrantsUberuser')->willReturn(false);
        $repository->method('findPermissionTemplateIdByName')->willReturn(1);
        $repository->method('getUserByUsername')->willReturn(null);
        $repository->method('createProvisionedUser')->willReturnCallback(function (array $data): int {
            $this->created[] = $data;
            return 55;
        });

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => $group === $method && $key === 'default_permission_template' ? 'Administrator' : $default
        );

        $service = new UserProvisioningService(
            $config,
            $this->createMock(Logger::class),
            $repository,
            $this->createMock(ExternalIdentityRepositoryInterface::class),
            $this->createMock(UserGroupLookupInterface::class),
            $this->createMock(UserGroupMemberRepositoryInterface::class)
        );

        $userInfo = $this->createMock(UserInfoInterface::class);
        $userInfo->method('getUsername')->willReturn($username);
        $userInfo->method('getEmail')->willReturn('bob@example.com');
        $userInfo->method('getDisplayName')->willReturn('Bob');
        $userInfo->method('getFullName')->willReturn('Bob');
        $userInfo->method('getGroups')->willReturn([]);

        $method_ = (new ReflectionClass(UserProvisioningService::class))->getMethod('createNewUser');

        return $method_->invoke($service, $userInfo, 'provider', $method);
    }
}
