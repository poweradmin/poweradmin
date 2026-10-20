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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\RepositoryFactoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Zone\ZoneLimitBreach;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use ReflectionClass;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\StubActor;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * addOwnerToZone() is the guarded write behind the change-owner page: it
 * refuses callers without meta-edit rights and users that do not exist.
 */
#[CoversClass(DomainManager::class)]
class DomainManagerAddOwnerTest extends PermissionServiceTestCase
{

    private const CALLER_ID = 7;

    private function manager(array $callerPermissions, array $existingUsers, bool $sharedZoneId = false, ?ZoneRepositoryInterface $zones = null, ?ZoneOwnershipLimit $ownershipLimit = null): DomainManager
    {
        $reflection = new ReflectionClass(DomainManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();

        $users = $this->createMock(DbUserRepository::class);
        $users->method('getUserById')
            ->willReturnCallback(fn(int $id): ?array => in_array($id, $existingUsers, true) ? ['id' => $id] : null);

        foreach (
            [
            'actor' => new StubActor(self::CALLER_ID),
            'userRepository' => $users,
            'permissionService' => $this->buildPermissionService(permissionsByUser: [self::CALLER_ID => $callerPermissions]),
            'repositoryFactory' => $this->repositoryFactory($sharedZoneId, $zones),
            'ownershipLimit' => $ownershipLimit,
            ] as $name => $value
        ) {
            $reflection->getProperty($name)->setValue($manager, $value);
        }

        return $manager;
    }

    public function testRefusesCallersWithoutMetaEditRights(): void
    {
        $result = $this->manager([], [42])->addOwnerToZone(5, 42);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::FORBIDDEN, $result->refusal);
    }

    public function testRefusesAnUnknownUserSoNoZoneEndsUpOrphaned(): void
    {
        $result = $this->manager([Permission::PERM_ZONE_META_EDIT_OTHERS], [])->addOwnerToZone(5, 42);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::NOT_FOUND, $result->refusal);
        $this->assertSame('Unknown user ID: 42', $result->message);
    }

    public function testRefusesAZoneIdTwoZonesShareBecauseTheGrantWouldNotCount(): void
    {
        $result = $this->manager([Permission::PERM_ZONE_META_EDIT_OTHERS], [42], sharedZoneId: true)->addOwnerToZone(5, 42);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    public function testAnExistingOwnerIsNotCountedAgainstTheLimit(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('isUserZoneOwner')->with(5, 42)->willReturn(true);
        $zones->expects($this->never())->method('addOwnerToZone');
        $limit = $this->createMock(ZoneOwnershipLimit::class);
        $limit->expects($this->never())->method('addUserOwner');

        $result = $this->manager([Permission::PERM_ZONE_META_EDIT_OTHERS], [42], false, $zones, $limit)->addOwnerToZone(5, 42);

        $this->assertTrue($result->success);
    }

    public function testRefusesAUserAtTheirZoneLimitWithoutWriting(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('isUserZoneOwner')->willReturn(false);
        $zones->expects($this->never())->method('addOwnerToZone');
        $limit = $this->createMock(ZoneOwnershipLimit::class);
        $limit->method('addUserOwner')->with(42)->willReturn(new ZoneLimitBreach(ZoneLimitBreach::SUBJECT_USER, 'alice', 4, 4));

        $result = $this->manager([Permission::PERM_ZONE_META_EDIT_OTHERS], [42], false, $zones, $limit)->addOwnerToZone(5, 42);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::CONFLICT, $result->refusal);
        $this->assertSame('Zone limit reached: alice owns 4 of 4 zones.', $result->message);
    }

    public function testAddsAUserWithinTheirZoneLimit(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('isUserZoneOwner')->willReturn(false);
        $zones->expects($this->once())->method('addOwnerToZone')->with(5, 42)->willReturn(true);
        $limit = $this->createMock(ZoneOwnershipLimit::class);
        $limit->method('addUserOwner')->with(42)->willReturnCallback(static fn(int $id, callable $write): mixed => $write());

        $result = $this->manager([Permission::PERM_ZONE_META_EDIT_OTHERS], [42], false, $zones, $limit)->addOwnerToZone(5, 42);

        $this->assertTrue($result->success);
    }

    private function repositoryFactory(bool $sharedZoneId, ?ZoneRepositoryInterface $zones): RepositoryFactoryInterface
    {
        if ($zones === null) {
            $zones = $this->createMock(ZoneRepositoryInterface::class);
            $zones->method('isSharedZoneId')->willReturn($sharedZoneId);
            $zones->expects($sharedZoneId ? $this->never() : $this->any())->method('addOwnerToZone')->willReturn(true);
        }

        $factory = $this->createStub(RepositoryFactoryInterface::class);
        $factory->method('createZoneRepository')->willReturn($zones);

        return $factory;
    }
}
