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
use Poweradmin\Application\Controller\Api\V2\GroupsController;
use Poweradmin\Application\Controller\Api\V2\GroupZonesController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\User\GroupService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Zone\ZoneGroupService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Zone\ZoneLimitBreach;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Group zone limits over /api/v2 (#72): max_zones on create and update, and a
 * 409 when granting a zone would take a group past its limit.
 */
class GroupsControllerZoneLimitTest extends V2ControllerTestCase
{
    private const GROUP_ID = 3;

    /** @var ZoneOwnershipLimit&MockObject */
    private ZoneOwnershipLimit $limit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limit = $this->createMock(ZoneOwnershipLimit::class);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidLimits(): array
    {
        return [
            'negative' => [-1],
            'string' => ['10'],
            'float' => [2.5],
            'too large' => [ZoneOwnershipLimit::MAX_LIMIT + 1],
        ];
    }

    #[DataProvider('invalidLimits')]
    public function testCreateRefusesAnInvalidLimitBeforeCreating(mixed $value): void
    {
        $groupService = $this->createMock(GroupService::class);
        $groupService->expects($this->never())->method('createGroup');
        $this->limit->expects($this->never())->method('setGroupLimit');

        $response = $this->callGroups('createGroup', 'POST', ['name' => 'ops', 'perm_templ_id' => 2, 'max_zones' => $value], $groupService);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringStartsWith('max_zones must be null or a whole number', $this->messageOf($response));
    }

    public function testCreateStoresTheLimit(): void
    {
        $groupService = $this->createMock(GroupService::class);
        $groupService->method('createGroup')->willReturn(new UserGroup(self::GROUP_ID, 'ops', null, 2));
        $this->limit->expects($this->once())->method('setGroupLimit')->with(1, self::GROUP_ID, 25)->willReturn(['success' => true]);

        $response = $this->callGroups('createGroup', 'POST', ['name' => 'ops', 'perm_templ_id' => 2, 'max_zones' => 25], $groupService);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(25, $this->decode($response)['data']['group']['max_zones']);
    }

    public function testCreateWithoutALimitStoresNone(): void
    {
        $groupService = $this->createMock(GroupService::class);
        $groupService->method('createGroup')->willReturn(new UserGroup(self::GROUP_ID, 'ops', null, 2));
        $this->limit->expects($this->never())->method('setGroupLimit');

        $response = $this->callGroups('createGroup', 'POST', ['name' => 'ops', 'perm_templ_id' => 2], $groupService);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertNull($this->decode($response)['data']['group']['max_zones']);
    }

    #[DataProvider('invalidLimits')]
    public function testUpdateRefusesAnInvalidLimitBeforeUpdating(mixed $value): void
    {
        $groupService = $this->createMock(GroupService::class);
        $groupService->expects($this->never())->method('updateGroup');
        $this->limit->expects($this->never())->method('setGroupLimit');

        $response = $this->callGroups('updateGroup', 'PUT', ['max_zones' => $value], $groupService);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdateClearsTheLimitWithNull(): void
    {
        $groupService = $this->createMock(GroupService::class);
        $groupService->method('updateGroup')->willReturn(new UserGroup(self::GROUP_ID, 'ops', null, 2, null, null, null, 5));
        $this->limit->expects($this->once())->method('setGroupLimit')->with(1, self::GROUP_ID, null)->willReturn(['success' => true]);

        $response = $this->callGroups('updateGroup', 'PUT', ['max_zones' => null], $groupService);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->decode($response)['data']['group']['max_zones']);
    }

    public function testUpdateWithoutTheFieldKeepsTheStoredLimit(): void
    {
        $groupService = $this->createMock(GroupService::class);
        $groupService->method('updateGroup')->willReturn(new UserGroup(self::GROUP_ID, 'ops', null, 2, null, null, null, 5));
        $this->limit->expects($this->never())->method('setGroupLimit');

        $response = $this->callGroups('updateGroup', 'PUT', ['name' => 'ops'], $groupService);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(5, $this->decode($response)['data']['group']['max_zones']);
    }

    public function testAssigningAZoneToAGroupAtItsLimitIsAConflict(): void
    {
        $zoneGroupService = $this->createMock(ZoneGroupService::class);
        $zoneGroupService->method('addGroupToZone')->with(7, self::GROUP_ID)
            ->willReturn(new ZoneLimitBreach(ZoneLimitBreach::SUBJECT_GROUP, 'ops', 2, 2));

        $controller = $this->bareController(GroupZonesController::class);
        $this->injectBaseCollaborators($controller, 'POST', ['zone_id' => 7]);
        $config = $this->config();
        $this->inject($controller, 'config', $config);

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('zoneIdExists')->willReturn(true);

        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'domainRepository', $domainRepository);
        $this->inject($controller, 'zoneGroupService', $zoneGroupService);
        $this->inject($controller, 'pathParameters', ['id' => self::GROUP_ID]);
        $this->inject($controller, 'authenticatedUserId', 1);
        $this->inject($controller, 'serviceFactory', $this->factory($config));

        $response = $this->callHandler($controller, 'assignZone');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Zone limit reached: group ops owns 2 of 2 zones.', $this->messageOf($response));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function callGroups(string $handler, string $method, array $body, GroupService $groupService): JsonResponse
    {
        $controller = $this->bareController(GroupsController::class);
        $this->injectBaseCollaborators($controller, $method, $body);
        $config = $this->config();
        $this->inject($controller, 'config', $config);

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canManageGroups')->willReturn(true);
        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'groupService', $groupService);
        $this->inject($controller, 'pathParameters', ['id' => self::GROUP_ID]);
        $this->inject($controller, 'authenticatedUserId', 1);
        $this->inject($controller, 'serviceFactory', $this->factory($config));

        return $this->callHandler($controller, $handler);
    }

    private function config(): ConfigurationInterface
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(static fn(string $group, string $key, $default = null) => $default);

        return $config;
    }

    private function factory(ConfigurationInterface $config): ControllerServiceFactory
    {
        $templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $templates->method('validateTemplateType')->willReturn(true);

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $factory->method('userRepository')->willReturn($this->stubUsers());
        $factory->method('permissionTemplateRepository')->willReturn($templates);
        $factory->method('zoneOwnershipModeService')->willReturn(new ZoneOwnershipModeService($config));
        $factory->method('zoneOwnershipLimit')->willReturn($this->limit);

        return $factory;
    }
}
