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
use Poweradmin\Application\Controller\Api\V2\ZoneOwnersController;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Model\ZoneGroup;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * DELETE /zones/{id}/owners/{user_id} keeps its contract wording and 400
 * status for every last-owner refusal, per ownership mode.
 */
class ZoneOwnersControllerLastOwnerTest extends V2ControllerTestCase
{
    private const ZONE_ID = 7;

    /** @var ZoneOwnershipRepositoryInterface&MockObject */
    private ZoneOwnershipRepositoryInterface $zoneRepository;

    /** @var ZoneGroupRepositoryInterface&MockObject */
    private ZoneGroupRepositoryInterface $zoneGroupRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zoneRepository = $this->createMock(ZoneOwnershipRepositoryInterface::class);
        $this->zoneGroupRepository = $this->createMock(ZoneGroupRepositoryInterface::class);
    }

    /**
     * @return array<string, array{0: string, 1: list<int>, 2: list<int>, 3: string}>
     */
    public static function refusals(): array
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
    #[DataProvider('refusals')]
    public function testRemovalIsRefusedWithTheContractWording(string $mode, array $owners, array $groups, string $message): void
    {
        $this->givenOwnership($owners, $groups);
        $this->zoneRepository->expects($this->never())->method('removeOwnerFromZone');

        $response = $this->remove($mode, 5);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame($message, $this->messageOf($response));
    }

    public function testAnOwnerWithAnotherOwnerLeftIsRemoved(): void
    {
        $this->givenOwnership([5, 6], []);
        $this->zoneRepository->expects($this->once())->method('removeOwnerFromZone')->with(self::ZONE_ID, 5)->willReturn(true);

        $response = $this->remove('both', 5);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Owner removed successfully', $this->messageOf($response));
    }

    public function testAUserWhoIsNotAnOwnerIs404WithoutTheGuard(): void
    {
        $this->givenOwnership([5], []);
        $this->zoneRepository->expects($this->once())->method('removeOwnerFromZone')->with(self::ZONE_ID, 9)->willReturn(false);

        $response = $this->remove('both', 9);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Owner not found for this zone', $this->messageOf($response));
    }

    /**
     * @param list<int> $owners
     * @param list<int> $groups
     */
    private function givenOwnership(array $owners, array $groups): void
    {
        $this->zoneRepository->method('isUserZoneOwner')->willReturnCallback(
            static fn(int $zoneId, int $userId): bool => in_array($userId, $owners, true)
        );
        $this->zoneRepository->method('getZoneOwners')->with(self::ZONE_ID)->willReturn(
            array_map(static fn(int $id): array => ['id' => $id, 'fullname' => 'User ' . $id], $owners)
        );
        $this->zoneGroupRepository->method('findByDomainId')->with(self::ZONE_ID)->willReturn(
            array_map(static fn(int $id): ZoneGroup => ZoneGroup::create(self::ZONE_ID, $id), $groups)
        );
    }

    private function remove(string $mode, int $userId): JsonResponse
    {
        $controller = $this->bareController(ZoneOwnersController::class);
        $this->injectBaseCollaborators($controller, 'DELETE');

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            static fn(string $group, string $key, $default = null) => ($group === 'dns' && $key === 'zone_ownership_mode') ? $mode : $default
        );
        $this->inject($controller, 'config', $config);

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('zoneIdExists')->willReturn(true);
        $domainRepository->method('getDomainNameById')->willReturn('example.com');

        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'domainRepository', $domainRepository);
        $this->inject($controller, 'zoneRepository', $this->zoneRepository);
        $this->inject($controller, 'auditService', $this->createMock(AuditService::class));
        $this->inject($controller, 'pathParameters', ['id' => self::ZONE_ID, 'user_id' => $userId]);
        $this->inject($controller, 'authenticatedUserId', 1);
        $this->inject($controller, 'serviceFactory', $this->factory($config));

        return $this->callHandler($controller, 'removeOwner');
    }

    private function factory(ConfigurationManager $config): ControllerServiceFactory
    {
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $factory->method('userRepository')->willReturn($this->stubUsers());
        $factory->method('zoneOwnershipGuard')->willReturn(
            new ZoneOwnershipGuard($this->zoneRepository, $this->zoneGroupRepository, new ZoneOwnershipModeService($config))
        );
        $factory->method('permissionService')->willReturn($this->createMock(PermissionService::class));

        return $factory;
    }
}
