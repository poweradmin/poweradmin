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

namespace Poweradmin\Tests\Unit\Api\V1;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V1\ZonesController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ZoneManagementService;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * Every field PUT /api/v1/zones/{id} writes - name, type and master - is zone
 * metadata, which the web edit form gates behind zone_meta_edit_*. The v1 handler
 * is still shipped on the release branches, so it needs the same gate as v2.
 */
class ZoneMetadataPermissionTest extends TestCase
{
    private const ZONE_ID = 3;
    private const USER_ID = 7;

    public function testContentOnlyUserCannotRenameZone(): void
    {
        $this->assertSame(403, $this->updateZone(['name' => 'bigbank.example'], meta: false));
    }

    public function testContentOnlyUserCannotRepointZoneAtForeignMaster(): void
    {
        $this->assertSame(403, $this->updateZone(['type' => 'SLAVE', 'master' => '203.0.113.66'], meta: false));
    }

    public function testMetaUserMayChangeZoneType(): void
    {
        $this->assertSame(200, $this->updateZone(['type' => 'NATIVE'], meta: true));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function updateZone(array $body, bool $meta): int
    {
        $permissionService = $this->createMock(ApiPermissionService::class);
        $permissionService->method('canEditZoneMeta')->willReturn($meta);
        // The content permission the handler used to trust is granted throughout,
        // so a refusal can only come from the metadata gate.
        $permissionService->method('canEditZone')->willReturn(true);

        $zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $zoneRepository->method('zoneIdExists')->willReturn(true);
        $zoneRepository->method('getZoneById')->willReturn([
            'id' => self::ZONE_ID,
            'name' => 'tenant.example',
            'type' => 'MASTER',
            'master' => '',
            'account' => '',
        ]);

        $zoneManagementService = $this->createMock(ZoneManagementService::class);
        $zoneManagementService->method('updateZone')->willReturn(['success' => true]);

        $controller = (new ReflectionClass(ZonesController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'permissionService', $permissionService);
        $this->inject($controller, 'zoneRepository', $zoneRepository);
        $this->inject($controller, 'zoneManagementService', $zoneManagementService);
        $this->inject($controller, 'request', new Request([], [], [], [], [], [], json_encode($body)));
        $this->inject($controller, 'pathParameters', ['id' => self::ZONE_ID]);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);

        $reflection = new ReflectionClass($controller);

        return $reflection->getMethod('updateZone')->invoke($controller)->getStatusCode();
    }

    private function inject(object $controller, string $property, mixed $value): void
    {
        (new ReflectionClass($controller))->getProperty($property)->setValue($controller, $value);
    }
}
