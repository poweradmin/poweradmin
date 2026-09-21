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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V2\GroupZonesController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\ZoneGroupService;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * DELETE /groups/{id}/zones/{zone_id} keeps its contract wording and 400
 * status for every last-owner refusal, per ownership mode.
 */
class GroupZonesControllerLastOwnerTest extends V2ControllerTestCase
{
    private const ZONE_ID = 7;
    private const GROUP_ID = 3;

    /** @var ZoneOwnershipRepositoryInterface&MockObject */
    private ZoneOwnershipRepositoryInterface $zoneRepository;

    /** @var ZoneGroupRepositoryInterface&MockObject */
    private ZoneGroupRepositoryInterface $zoneGroupRepository;

    /** @var ZoneGroupService&MockObject */
    private ZoneGroupService $zoneGroupService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zoneRepository = $this->createMock(ZoneOwnershipRepositoryInterface::class);
        $this->zoneGroupRepository = $this->createMock(ZoneGroupRepositoryInterface::class);
        $this->zoneGroupService = $this->createMock(ZoneGroupService::class);
    }

    /**
     * @return array<string, array{0: string, 1: list<int>, 2: list<int>, 3: string}>
     */
    public static function refusals(): array
    {
        return [
            'both, sole group, no owners' => ['both', [], [3], 'Cannot remove the last owner: this would leave the zone with no ownership. Add another group or a user owner first.'],
            'groups_only, sole group, no owners' => ['groups_only', [], [3], 'Cannot remove the last owner: this would leave the zone with no ownership. Add another group first (zone ownership mode is groups_only).'],
            'users_only, sole group, no owners' => ['users_only', [], [3], 'Cannot remove the last owner: this would leave the zone with no ownership. Add a user owner first (zone ownership mode is users_only).'],
            'groups_only, sole group, legacy owner' => ['groups_only', [5], [3], 'Cannot remove the last group: zone ownership mode is groups_only and requires at least one group. Add another group first.'],
            'users_only, second group, no owners' => ['users_only', [], [3, 4], 'Cannot remove group: zone ownership mode is users_only and the zone has no user owners. Add a user owner first.'],
        ];
    }

    /**
     * @param list<int> $owners
     * @param list<int> $groups
     */
    #[DataProvider('refusals')]
    public function testUnassignIsRefusedWithTheContractWording(string $mode, array $owners, array $groups, string $message): void
    {
        $this->givenOwnership($owners, $groups);
        $this->zoneGroupService->expects($this->never())->method('removeGroupFromZone');

        $response = $this->unassign($mode);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame($message, $this->messageOf($response));
    }

    public function testAGroupWithAUserOwnerLeftIsUnassigned(): void
    {
        $this->givenOwnership([5], [3]);
        $this->zoneGroupService->expects($this->once())->method('removeGroupFromZone')->with(self::ZONE_ID, self::GROUP_ID)->willReturn(true);

        $response = $this->unassign('both');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Zone unassigned successfully', $this->messageOf($response));
    }

    public function testAGroupThatDoesNotOwnTheZoneIs404WithoutTheGuard(): void
    {
        $this->givenOwnership([], [4]);
        $this->zoneGroupService->expects($this->once())->method('removeGroupFromZone')->with(self::ZONE_ID, self::GROUP_ID)->willReturn(false);

        $response = $this->unassign('both');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone assignment not found', $this->messageOf($response));
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

    private function unassign(string $mode): JsonResponse
    {
        $controller = $this->bareController(GroupZonesController::class);
        $this->injectBaseCollaborators($controller, 'DELETE');

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            static fn(string $group, string $key, $default = null) => ($group === 'dns' && $key === 'zone_ownership_mode') ? $mode : $default
        );
        $this->inject($controller, 'config', $config);

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('zoneIdExists')->willReturn(true);

        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'domainRepository', $domainRepository);
        $this->inject($controller, 'zoneGroupService', $this->zoneGroupService);
        $this->inject($controller, 'pathParameters', ['id' => self::GROUP_ID, 'zone_id' => self::ZONE_ID]);
        $this->inject($controller, 'authenticatedUserId', 1);
        $this->inject($controller, 'serviceFactory', $this->factory($config));

        return $this->callHandler($controller, 'unassignZone');
    }

    private function factory(ConfigurationManager $config): ControllerServiceFactory
    {
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $factory->method('userRepository')->willReturn($this->stubUsers());
        $factory->method('zoneOwnershipGuard')->willReturn(
            new ZoneOwnershipGuard($this->zoneRepository, $this->zoneGroupRepository, new ZoneOwnershipModeService($config))
        );

        return $factory;
    }
}
