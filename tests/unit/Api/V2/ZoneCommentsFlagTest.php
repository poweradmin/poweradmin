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

namespace Poweradmin\Tests\Unit\Api\V2;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * With interface.show_zone_comments off, the zone API refuses to write a description but still returns the stored one.
 */
class ZoneCommentsFlagTest extends TestCase
{
    private const ZONE_ID = 3;

    private ZoneRepositoryInterface $zoneRepository;
    private ZoneManagementService $zoneManagementService;

    public function testCreateWithADescriptionIsRefusedBeforeAnythingIsCreated(): void
    {
        $this->zoneManagementService = $this->createMock(ZoneManagementService::class);
        $this->zoneManagementService->expects($this->never())->method('createZone');
        $this->zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $this->zoneRepository->expects($this->never())->method('updateZoneComment');

        $response = $this->call('createZone', ['name' => 'new.example', 'description' => 'hello'], false);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Zone comments feature is disabled in configuration', (string)$response->getContent());
    }

    public function testChangingTheDescriptionIsRefused(): void
    {
        $this->arrange('stored comment');
        $this->zoneRepository->expects($this->never())->method('updateZoneComment');

        $response = $this->call('updateZone', ['description' => 'changed'], false);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRepeatingTheStoredDescriptionIsANoOp(): void
    {
        $this->arrange('stored comment');

        $response = $this->call('updateZone', ['description' => 'stored comment'], false);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('stored comment', json_decode((string)$response->getContent(), true)['data']['zone']['description']);
    }

    public function testTheStoredDescriptionIsStillReturnedWhileTheFlagIsOff(): void
    {
        $this->arrange('stored comment');

        $off = json_decode((string)$this->call('getZone', [], false)->getContent(), true);
        $on = json_decode((string)$this->call('getZone', [], true)->getContent(), true);

        $this->assertSame('stored comment', $off['data']['zone']['description']);
        $this->assertSame('stored comment', $on['data']['zone']['description']);
    }

    private function arrange(string $storedComment): void
    {
        $this->zoneManagementService = $this->createMock(ZoneManagementService::class);
        $this->zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $this->zoneRepository->method('zoneIdExists')->willReturn(true);
        $this->zoneRepository->method('getZoneById')->willReturn([
            'id' => self::ZONE_ID,
            'name' => 'tenant.example',
            'type' => 'MASTER',
            'master' => '',
            'account' => '',
        ]);
        $this->zoneRepository->method('getZoneComment')->willReturn($storedComment);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function call(string $method, array $body, bool $commentsEnabled): \Symfony\Component\HttpFoundation\Response
    {
        $permissionService = $this->createMock(ApiPermissionService::class);
        $permissionService->method('canEditZoneMeta')->willReturn(true);
        $permissionService->method('canCreateZone')->willReturn(true);
        $permissionService->method('canEditZone')->willReturn(true);
        $permissionService->method('canViewZone')->willReturn(true);

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            static fn(string $group, string $key, mixed $default = null): mixed => $group === 'interface' && $key === 'show_zone_comments' ? $commentsEnabled : $default
        );

        $controller = (new ReflectionClass(ZonesController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'config', $config);
        $this->inject($controller, 'permissionService', $permissionService);
        $this->inject($controller, 'zoneRepository', $this->zoneRepository);
        $this->inject($controller, 'zoneManagementService', $this->zoneManagementService);
        $this->inject($controller, 'request', new Request([], [], [], [], [], [], json_encode($body)));
        $this->inject($controller, 'pathParameters', ['id' => self::ZONE_ID]);
        $this->inject($controller, 'authenticatedUserId', 7);
        $this->inject($controller, 'ipAddressValidator', new IPAddressValidator());
        $this->inject($controller, 'logger', $this->createMock(LoggerInterface::class));

        return (new ReflectionClass($controller))->getMethod($method)->invoke($controller);
    }

    private function inject(object $controller, string $property, mixed $value): void
    {
        (new ReflectionClass($controller))->getProperty($property)->setValue($controller, $value);
    }
}
