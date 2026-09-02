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
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\ZoneManagementService;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * The web edit form gates a zone's type and slave master behind zone_meta_edit_*,
 * and offers no rename at all. PUT /api/v2/zones/{id} writes name, type and master,
 * so it must demand the same permission - the record-content permission is not enough.
 * A value that repeats what is stored changes nothing, so it needs no permission:
 * clients such as the Terraform provider resend the type on every update.
 */
class ZoneMetadataPermissionTest extends TestCase
{
    private const ZONE_ID = 3;
    private const USER_ID = 7;

    public function testContentOnlyUserCannotRenameZone(): void
    {
        $response = $this->updateZone(['name' => 'bigbank.example'], meta: false, content: true);

        $this->assertSame(403, $response['status']);
    }

    public function testContentOnlyUserCannotRepointZoneAtForeignMaster(): void
    {
        $response = $this->updateZone(
            ['type' => 'SLAVE', 'master' => '203.0.113.66'],
            meta: false,
            content: true
        );

        $this->assertSame(403, $response['status']);
    }

    public function testContentOnlyUserMayStillEditDescription(): void
    {
        $response = $this->updateZone(['description' => 'still allowed'], meta: false, content: true);

        $this->assertSame(200, $response['status']);
    }

    public function testContentOnlyUserMayRepeatStoredTypeAlongsideDescription(): void
    {
        $response = $this->updateZone(
            ['type' => 'master', 'master' => '', 'description' => 'still allowed'],
            meta: false,
            content: true
        );

        $this->assertSame(200, $response['status']);
    }

    public function testContentOnlyUserMayRepeatStoredName(): void
    {
        $response = $this->updateZone(['name' => 'tenant.example.'], meta: false, content: true);

        $this->assertSame(200, $response['status']);
    }

    public function testContentOnlyUserMayRepeatStoredSlaveTypeWithoutResendingMaster(): void
    {
        $response = $this->updateZone(
            ['type' => 'SLAVE', 'description' => 'still allowed'],
            meta: false,
            content: true,
            stored: ['type' => 'SLAVE', 'master' => '203.0.113.1']
        );

        $this->assertSame(200, $response['status']);
    }

    public function testContentOnlyUserCannotChangeMasterWhileRepeatingStoredType(): void
    {
        $response = $this->updateZone(
            ['type' => 'MASTER', 'master' => '203.0.113.66'],
            meta: false,
            content: true
        );

        $this->assertSame(403, $response['status']);
    }

    public function testMetaUserMayChangeZoneType(): void
    {
        $response = $this->updateZone(['type' => 'NATIVE'], meta: true, content: false);

        $this->assertSame(200, $response['status']);
    }

    public function testMetaUserMayNotEditDescriptionWithoutContentPermission(): void
    {
        $response = $this->updateZone(['description' => 'nope'], meta: true, content: false);

        $this->assertSame(403, $response['status']);
    }

    public function testUserWithNeitherPermissionIsRefused(): void
    {
        $response = $this->updateZone(['name' => 'bigbank.example'], meta: false, content: false);

        $this->assertSame(403, $response['status']);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $stored Overrides for the stored zone row
     * @return array{status: int, body: string}
     */
    private function updateZone(array $body, bool $meta, bool $content, array $stored = []): array
    {
        $permissionService = $this->createMock(ApiPermissionService::class);
        $permissionService->method('canEditZoneMeta')->willReturn($meta);
        $permissionService->method('canEditZone')->willReturn($content);

        $zoneRepository = $this->createMock(DbZoneRepository::class);
        $zoneRepository->method('zoneIdExists')->willReturn(true);
        $zoneRepository->method('getZoneById')->willReturn($stored + [
            'id' => self::ZONE_ID,
            'name' => 'tenant.example',
            'type' => 'MASTER',
            'master' => '',
            'account' => '',
        ]);
        $zoneRepository->method('getZoneComment')->willReturn('');

        $zoneManagementService = $this->createMock(ZoneManagementService::class);
        $zoneManagementService->method('updateZone')->willReturn(['success' => true]);

        $controller = (new ReflectionClass(ZonesController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'permissionService', $permissionService);
        $this->inject($controller, 'zoneRepository', $zoneRepository);
        $this->inject($controller, 'zoneManagementService', $zoneManagementService);
        $this->inject($controller, 'request', new Request([], [], [], [], [], [], json_encode($body)));
        $this->inject($controller, 'pathParameters', ['id' => self::ZONE_ID]);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'ipAddressValidator', new IPAddressValidator());

        $reflection = new ReflectionClass($controller);
        $response = $reflection->getMethod('updateZone')->invoke($controller);

        return ['status' => $response->getStatusCode(), 'body' => (string)$response->getContent()];
    }

    private function inject(object $controller, string $property, mixed $value): void
    {
        (new ReflectionClass($controller))->getProperty($property)->setValue($controller, $value);
    }
}
