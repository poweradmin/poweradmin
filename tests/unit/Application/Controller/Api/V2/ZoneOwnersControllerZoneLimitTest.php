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

use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V2\ZoneOwnersController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneLimitBreach;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * POST /zones/{id}/owners refuses a user already at their zone limit (#72).
 */
class ZoneOwnersControllerZoneLimitTest extends V2ControllerTestCase
{
    private const ZONE_ID = 7;
    private const FULL_USER = 5;

    /** @var ZoneOwnershipRepositoryInterface&MockObject */
    private ZoneOwnershipRepositoryInterface $zoneRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zoneRepository = $this->createMock(ZoneOwnershipRepositoryInterface::class);
        $this->zoneRepository->method('isSharedZoneId')->willReturn(false);
        $this->zoneRepository->method('isUserZoneOwner')->willReturn(false);
    }

    public function testASingleAddPastTheLimitIsAConflictAndWritesNothing(): void
    {
        $this->zoneRepository->expects($this->never())->method('addOwnerToZone');

        $response = $this->addOwner(['user_id' => self::FULL_USER]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Zone limit reached: user alice owns 2 of 2 zones.', $this->messageOf($response));
    }

    public function testASingleAddWithinTheLimitIsAdded(): void
    {
        $this->zoneRepository->expects($this->once())->method('addOwnerToZone')->with(self::ZONE_ID, 6);

        $response = $this->addOwner(['user_id' => 6]);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testABatchListsUsersAtTheirLimitAndAddsTheRest(): void
    {
        $this->zoneRepository->expects($this->once())->method('addOwnerToZone')->with(self::ZONE_ID, 6);

        $response = $this->addOwner(['user_ids' => [self::FULL_USER, 6]]);

        $this->assertSame(201, $response->getStatusCode());
        $data = $this->decode($response)['data'];
        $this->assertSame([6], $data['added']);
        $this->assertSame([self::FULL_USER], $data['over_limit']);
        $this->assertSame('1 owner(s) added, 1 at their zone limit', $this->messageOf($response));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function addOwner(array $body): JsonResponse
    {
        $controller = $this->bareController(ZoneOwnersController::class);
        $this->injectBaseCollaborators($controller, 'POST', $body);

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(static fn(string $group, string $key, $default = null) => $default);
        $this->inject($controller, 'config', $config);

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('zoneIdExists')->willReturn(true);
        $domainRepository->method('getDomainNameById')->willReturn('example.com');
        $users = $this->createMock(UserLookupInterface::class);
        $users->method('getUserById')->willReturnCallback(static fn(int $id): array => ['id' => $id, 'username' => 'user' . $id]);

        $limit = $this->createMock(ZoneOwnershipLimit::class);
        $limit->method('userBreach')->willReturnCallback(
            static fn(int $id): ?ZoneLimitBreach => $id === self::FULL_USER ? new ZoneLimitBreach(ZoneLimitBreach::SUBJECT_USER, 'alice', 2, 2) : null
        );

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $factory->method('userRepository')->willReturn($this->stubUsers());
        $factory->method('permissionService')->willReturn($this->createMock(PermissionService::class));
        $factory->method('zoneOwnershipModeService')->willReturn(new ZoneOwnershipModeService($config));
        $factory->method('zoneOwnershipLimit')->willReturn($limit);

        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'domainRepository', $domainRepository);
        $this->inject($controller, 'zoneRepository', $this->zoneRepository);
        $this->inject($controller, 'userRepository', $users);
        $this->inject($controller, 'auditService', $this->createMock(AuditService::class));
        $this->inject($controller, 'pathParameters', ['id' => self::ZONE_ID]);
        $this->inject($controller, 'authenticatedUserId', 1);
        $this->inject($controller, 'serviceFactory', $factory);

        return $this->callHandler($controller, 'addOwner');
    }
}
