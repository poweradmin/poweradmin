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

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Domain\Model\UberuserChange;
use Poweradmin\Domain\Model\UserGroupMember;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Service\User\LastSuperAdminGuard;
use Poweradmin\Domain\Repository\ExternalIdentityRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\ValueObject\OidcUserInfo;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionMethod;
use TestHelpers\FakeConfiguration;

/**
 * The IdP group sync never removes the membership that holds the last active super
 * admin: that one removal is skipped with a warning and the sign-in carries on.
 */
class UserProvisioningServiceLastSuperAdminTest extends TestCase
{
    private const USER_ID = 5;
    private const ADMIN_GROUP_ID = 1;
    private const OTHER_GROUP_ID = 2;
    private const SECOND_ADMIN_GROUP_ID = 3;

    private UserRepositoryInterface&MockObject $users;
    private UserGroupMemberRepositoryInterface&MockObject $members;

    /** @var list<array{0: string, 1: string}> */
    private array $logged = [];

    /** @var list<int> Group ids the user is in, for the stateful membership double */
    private array $memberOf = [];

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->members = $this->createMock(UserGroupMemberRepositoryInterface::class);
        $this->members->method('findByUserId')->with(self::USER_ID)->willReturn([
            UserGroupMember::create(self::ADMIN_GROUP_ID, self::USER_ID),
            UserGroupMember::create(self::OTHER_GROUP_ID, self::USER_ID),
        ]);
    }

    private function syncWithNoExternalGroups(): void
    {
        $groups = $this->createMock(UserGroupLookupInterface::class);
        $groups->method('findIdByExactName')->willReturnMap([
            ['Admins', self::ADMIN_GROUP_ID],
            ['Operators', self::OTHER_GROUP_ID],
        ]);

        $logger = $this->createMock(Logger::class);
        $logger->method('log')->willReturnCallback(function (string $level, string $message): void {
            $this->logged[] = [$level, $message];
        });

        $service = new UserProvisioningService(
            new FakeConfiguration(['oidc' => ['group_mapping' => ['idp-admins' => 'Admins', 'idp-operators' => 'Operators']]]),
            $logger,
            $this->users,
            $this->createMock(ExternalIdentityRepositoryInterface::class),
            $groups,
            $this->members
        );

        (new ReflectionMethod($service, 'applyGroupMembership'))->invoke($service, self::USER_ID, [], 'oidc');
    }

    public function testTheMembershipHoldingTheLastSuperAdminStaysAndIsLogged(): void
    {
        $this->users->method('countUberusersAfter')->willReturnCallback(
            static fn(UberuserChange $change): int => $change->memberGroupId === self::ADMIN_GROUP_ID ? 0 : 1
        );
        $this->members->expects($this->once())->method('remove')
            ->with(self::OTHER_GROUP_ID, self::USER_ID)->willReturn(true);

        $this->syncWithNoExternalGroups();

        $warnings = array_values(array_filter($this->logged, static fn(array $entry): bool => $entry[0] === 'warning'));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('would leave no active super admin', $warnings[0][1]);
    }

    /**
     * Signs in a user whose template came from the provider mapping, with no mapped group left,
     * so the sync falls back to the default template.
     */
    private function syncTemplateFallingBackToDefault(?TransactionInterface $transaction = null): void
    {
        $this->users->method('getProvisioningProfile')->willReturn(['perm_templ' => 1, 'perm_templ_source' => 'oidc', 'auth_method' => 'oidc']);
        $this->users->method('findPermissionTemplateIdByName')->with('Guest')->willReturn(7);
        $this->users->method('templateGrantsUberuser')->with(7)->willReturn(false);
        $this->members->method('remove')->willReturn(true);
        $this->users->method('countUberusersAfter')->willReturn(1);

        $service = new UserProvisioningService(
            new FakeConfiguration(['oidc' => ['default_permission_template' => 'Guest']]),
            $this->createMock(Logger::class),
            $this->users,
            $this->createMock(ExternalIdentityRepositoryInterface::class),
            $this->createMock(UserGroupLookupInterface::class),
            $this->members,
            new LastSuperAdminGuard($this->users, $transaction)
        );

        (new ReflectionMethod($service, 'updateExistingUser'))
            ->invoke($service, self::USER_ID, new OidcUserInfo('admin', '', groups: []), 'oidc');
    }

    public function testTheProviderMappingDoesNotDemoteTheLastSuperAdmin(): void
    {
        $this->users->method('isLastUberuser')->with(self::USER_ID)->willReturn(true);
        $this->users->method('holdsUberuserThroughGroup')->willReturn(false);
        $this->users->expects($this->never())->method('updateProvisionedUser');

        $this->syncTemplateFallingBackToDefault();
    }

    public function testTheProviderMappingDemotesASuperAdminWhenAnotherRemains(): void
    {
        $this->users->method('isLastUberuser')->with(self::USER_ID)->willReturn(false);
        $this->users->expects($this->once())->method('updateProvisionedUser')
            ->with(self::USER_ID, $this->callback(static fn(array $updates): bool => ($updates['perm_templ'] ?? null) === 7));

        $this->syncTemplateFallingBackToDefault();
    }

    public function testTheTemplateCheckAndWriteShareOneTransaction(): void
    {
        $order = [];
        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->method('inTransaction')->willReturn(false);
        $transaction->method('begin')->willReturnCallback(function () use (&$order): void {
            $order[] = 'begin';
        });
        $transaction->method('commit')->willReturnCallback(function () use (&$order): void {
            $order[] = 'commit';
        });
        $this->users->method('isLastUberuser')->willReturnCallback(function () use (&$order): bool {
            $order[] = 'check';
            return false;
        });
        $this->users->method('updateProvisionedUser')->willReturnCallback(function () use (&$order): void {
            $order[] = 'write';
        });

        $this->syncTemplateFallingBackToDefault($transaction);

        $this->assertSame(['begin', 'check', 'write', 'commit'], array_slice($order, 0, 4));
    }

    public function testMembershipsGoWhenAnotherSuperAdminRemains(): void
    {
        $this->users->method('countUberusersAfter')->willReturn(1);
        $this->members->expects($this->exactly(2))->method('remove')->willReturn(true);

        $this->syncWithNoExternalGroups();
    }

    public function testAMoveBetweenAdminGroupsCompletesInOneSignIn(): void
    {
        $this->signInAsSoleSuperAdminOfTheFirstAdminGroupWith(['idp-admins-2']);

        $this->assertSame([self::SECOND_ADMIN_GROUP_ID], $this->memberOf);
        $this->assertSame([], $this->warnings());
    }

    public function testAMoveToANonAdminGroupKeepsTheAdminGroupWithAWarning(): void
    {
        $this->signInAsSoleSuperAdminOfTheFirstAdminGroupWith(['idp-operators']);

        $this->assertSame([self::ADMIN_GROUP_ID, self::OTHER_GROUP_ID], $this->memberOf);
        $warnings = $this->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('would leave no active super admin', $warnings[0][1]);
    }

    /**
     * The user is the only super admin, through the first admin group alone, and the
     * provider now sends $externalGroups. Memberships and the super admin count follow
     * the writes the sync makes.
     *
     * @param list<string> $externalGroups
     */
    private function signInAsSoleSuperAdminOfTheFirstAdminGroupWith(array $externalGroups): void
    {
        $this->memberOf = [self::ADMIN_GROUP_ID];
        $adminGroups = [self::ADMIN_GROUP_ID, self::SECOND_ADMIN_GROUP_ID];

        $this->members = $this->createMock(UserGroupMemberRepositoryInterface::class);
        $this->members->method('findByUserId')->willReturnCallback(
            fn(int $userId): array => array_map(static fn(int $groupId) => UserGroupMember::create($groupId, $userId), $this->memberOf)
        );
        $this->members->method('add')->willReturnCallback(function (int $groupId, int $userId): UserGroupMember {
            $this->memberOf[] = $groupId;
            sort($this->memberOf);
            return UserGroupMember::create($groupId, $userId);
        });
        $this->members->method('remove')->willReturnCallback(function (int $groupId): bool {
            $this->memberOf = array_values(array_diff($this->memberOf, [$groupId]));
            return true;
        });
        $this->users->method('countUberusersAfter')->willReturnCallback(
            function (UberuserChange $change) use ($adminGroups): int {
                $after = array_diff($this->memberOf, [$change->memberGroupId]);
                return array_intersect($after, $adminGroups) === [] ? 0 : 1;
            }
        );

        $groups = $this->createMock(UserGroupLookupInterface::class);
        $groups->method('findIdByExactName')->willReturnMap([
            ['Admins', self::ADMIN_GROUP_ID],
            ['Operators', self::OTHER_GROUP_ID],
            ['Admins 2', self::SECOND_ADMIN_GROUP_ID],
        ]);

        $logger = $this->createMock(Logger::class);
        $logger->method('log')->willReturnCallback(function (string $level, string $message): void {
            $this->logged[] = [$level, $message];
        });

        $service = new UserProvisioningService(
            new FakeConfiguration(['oidc' => [
                'allow_superuser_provisioning' => true,
                'group_mapping' => ['idp-admins' => 'Admins', 'idp-operators' => 'Operators', 'idp-admins-2' => 'Admins 2'],
            ]]),
            $logger,
            $this->users,
            $this->createMock(ExternalIdentityRepositoryInterface::class),
            $groups,
            $this->members
        );

        (new ReflectionMethod($service, 'applyGroupMembership'))->invoke($service, self::USER_ID, $externalGroups, 'oidc');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function warnings(): array
    {
        return array_values(array_filter($this->logged, static fn(array $entry): bool => $entry[0] === 'warning'));
    }
}
