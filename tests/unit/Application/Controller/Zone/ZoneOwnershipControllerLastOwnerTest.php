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

namespace Poweradmin\Tests\Unit\Application\Controller\Zone;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Application\Controller\Zone\ZoneOwnershipController;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;

/**
 * The ownership page refuses to remove the last owner a zone has, worded per
 * ownership mode, and never writes when it refuses.
 */
class ZoneOwnershipControllerLastOwnerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 7;

    /** @var ZoneRepositoryInterface&MockObject */
    private ZoneRepositoryInterface $zoneRepository;

    /** @var ZoneGroupRepositoryInterface&MockObject */
    private ZoneGroupRepositoryInterface $zoneGroupRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $this->zoneGroupRepository = $this->createMock(ZoneGroupRepositoryInterface::class);

        $this->factory->method('zoneRepository')->willReturn($this->zoneRepository);
        $this->factory->method('zoneGroupRepository')->willReturn($this->zoneGroupRepository);
        $this->factory->method('domainRepository')->willReturn($this->createMock(DomainRepositoryInterface::class));
        $this->factory->method('permissionService')->willReturn($this->createMock(PermissionService::class));
        $this->factory->method('auditService')->willReturn($this->createMock(AuditService::class));
    }

    /**
     * @return array<string, array{0: string, 1: list<int>, 2: list<int>, 3: string}>
     */
    public static function userOwnerRefusals(): array
    {
        return [
            'both, sole owner, no groups' => ['both', [5], [], 'Cannot remove the last owner: this would leave the zone with no ownership. Add another owner or a group first.'],
            'users_only, sole owner, no groups' => ['users_only', [5], [], 'Cannot remove the last owner: this would leave the zone with no ownership. Add another user owner first (zone ownership mode is users_only).'],
            'groups_only, sole owner, no groups' => ['groups_only', [5], [], 'Cannot remove the last owner: this would leave the zone with no ownership. Add a group first (zone ownership mode is groups_only).'],
            'users_only, sole owner, legacy group' => ['users_only', [5], [3], 'Cannot remove the last user owner: zone ownership mode is users_only and requires at least one user owner. Add another user owner first.'],
            'groups_only, second owner, no groups' => ['groups_only', [5, 6], [], 'Cannot remove user owner: zone ownership mode is groups_only and the zone has no group owners. Add a group first.'],
        ];
    }

    /**
     * @param list<int> $owners
     * @param list<int> $groups
     */
    #[DataProvider('userOwnerRefusals')]
    public function testUserOwnerRemovalIsRefusedWithTheModeWording(string $mode, array $owners, array $groups, string $message): void
    {
        $this->givenOwnership($owners, $groups);
        $this->zoneRepository->expects($this->never())->method('removeOwnerFromZone');

        $this->submit($mode, ['delete_owner' => '5']);

        $this->assertSame([['error', $message]], $this->messagesFor('zone-ownership'));
    }

    /**
     * @return array<string, array{0: string, 1: list<int>, 2: list<int>, 3: string}>
     */
    public static function groupRefusals(): array
    {
        return [
            'both, sole group, no owners' => ['both', [], [3], 'Cannot remove the last owner: this would leave the zone with no ownership. Add another owner or a group first.'],
            'groups_only, sole group, no owners' => ['groups_only', [], [3], 'Cannot remove the last owner: this would leave the zone with no ownership. Add a group first (zone ownership mode is groups_only).'],
            'users_only, sole group, no owners' => ['users_only', [], [3], 'Cannot remove the last owner: this would leave the zone with no ownership. Add another user owner first (zone ownership mode is users_only).'],
            'groups_only, sole group, legacy owner' => ['groups_only', [5], [3], 'Cannot remove the last group: zone ownership mode is groups_only and requires at least one group. Add another group first.'],
            'users_only, second group, no owners' => ['users_only', [], [3, 4], 'Cannot remove group: zone ownership mode is users_only and the zone has no user owners. Add a user owner first.'],
        ];
    }

    /**
     * @param list<int> $owners
     * @param list<int> $groups
     */
    #[DataProvider('groupRefusals')]
    public function testGroupRemovalIsRefusedWithTheModeWording(string $mode, array $owners, array $groups, string $message): void
    {
        $this->givenOwnership($owners, $groups);
        $this->zoneGroupRepository->expects($this->never())->method('remove');

        $this->submit($mode, ['delete_group' => '3']);

        $this->assertSame([['error', $message]], $this->messagesFor('zone-ownership'));
    }

    public function testAUserOwnerWithAnotherOwnerLeftIsRemoved(): void
    {
        $this->givenOwnership([5, 6], []);
        $this->zoneRepository->expects($this->once())->method('removeOwnerFromZone')->with(self::ZONE_ID, 5)->willReturn(true);

        $this->submit('both', ['delete_owner' => '5']);

        $this->assertSame([['success', 'Owner has been removed successfully.']], $this->messagesFor('zone-ownership'));
    }

    public function testAGroupWithAUserOwnerLeftIsRemoved(): void
    {
        $this->givenOwnership([5], [3]);
        $this->zoneGroupRepository->expects($this->once())->method('remove')->with(self::ZONE_ID, 3)->willReturn(true);

        $this->submit('both', ['delete_group' => '3']);

        $this->assertSame([['success', 'Group has been removed successfully.']], $this->messagesFor('zone-ownership'));
    }

    public function testASubmittedIdThatIsNotAnOwnerSkipsTheGuard(): void
    {
        $this->givenOwnership([5], []);
        $this->zoneRepository->expects($this->once())->method('removeOwnerFromZone')->with(self::ZONE_ID, 9)->willReturn(false);

        $this->submit('both', ['delete_owner' => '9']);

        $this->assertSame([], $this->messagesFor('zone-ownership'));
    }

    /**
     * @param list<int> $owners
     * @param list<int> $groups
     */
    private function givenOwnership(array $owners, array $groups): void
    {
        $this->zoneRepository->method('getZoneOwners')->with(self::ZONE_ID)->willReturn(
            array_map(static fn(int $id): array => ['id' => $id, 'fullname' => 'User ' . $id], $owners)
        );
        $this->zoneGroupRepository->method('findByDomainId')->with(self::ZONE_ID)->willReturn(
            array_map(static fn(int $id): ZoneGroup => ZoneGroup::create(self::ZONE_ID, $id), $groups)
        );
    }

    /** @param array<string, string> $fields */
    private function submit(string $mode, array $fields): void
    {
        $config = $this->configure(['dns' => ['zone_ownership_mode' => $mode]]);
        $this->factory->method('zoneOwnershipGuard')->willReturn(
            new ZoneOwnershipGuard($this->zoneRepository, $this->zoneGroupRepository, new ZoneOwnershipModeService($config), $this->createMock(TransactionInterface::class))
        );
        $this->post($fields);

        $controller = new ZoneOwnershipController([], true, $this->environment($config));

        $handler = new ReflectionMethod($controller, 'handleFormSubmission');
        $handler->invoke($controller, self::ZONE_ID, 'example.com', self::USER_ID, true);
    }
}
