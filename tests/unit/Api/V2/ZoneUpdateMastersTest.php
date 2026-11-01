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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * PUT /api/v2/zones/{id} accepts `masters` (string or array) like create does (#1847).
 */
class ZoneUpdateMastersTest extends TestCase
{
    private const ZONE_ID = 3;

    /** @var array<string, mixed>|null */
    private ?array $written = null;

    /**
     * @return array<string, array{string|list<string>}>
     */
    public static function mastersInputProvider(): array
    {
        return [
            'array' => [['192.0.2.9', '192.0.2.10']],
            'comma-separated string' => ['192.0.2.9, 192.0.2.10'],
        ];
    }

    /**
     * @param string|list<string> $masters
     */
    #[DataProvider('mastersInputProvider')]
    public function testMastersUpdatesASlaveZonesMasters(string|array $masters): void
    {
        $status = $this->updateZone(['masters' => $masters], ['type' => 'SLAVE', 'master' => '192.0.2.1']);

        $this->assertSame(200, $status);
        $this->assertSame(['master' => '192.0.2.9,192.0.2.10'], $this->written);
    }

    public function testConvertingToSlaveAcceptsMasters(): void
    {
        $status = $this->updateZone(['type' => 'SLAVE', 'masters' => ['192.0.2.9']]);

        $this->assertSame(200, $status);
        $this->assertSame(['type' => 'SLAVE', 'master' => '192.0.2.9'], $this->written);
    }

    public function testMastersTakesPrecedenceOverMaster(): void
    {
        $status = $this->updateZone(['master' => '192.0.2.5', 'masters' => ['192.0.2.9']], ['type' => 'SLAVE', 'master' => '192.0.2.1']);

        $this->assertSame(200, $status);
        $this->assertSame(['master' => '192.0.2.9'], $this->written);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedMastersProvider(): array
    {
        return [
            'integer' => [5],
            'array with a number' => [['192.0.2.1', 2]],
            'array with a list' => [[['192.0.2.1']]],
        ];
    }

    #[DataProvider('malformedMastersProvider')]
    public function testMalformedMastersIs400(mixed $masters): void
    {
        $this->assertSame(400, $this->updateZone(['masters' => $masters]));
        $this->assertNull($this->written);
    }

    public function testAnInvalidAddressInMastersIs400(): void
    {
        $this->assertSame(400, $this->updateZone(['masters' => ['not-an-ip']]));
        $this->assertNull($this->written);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $stored Overrides for the stored zone row
     */
    private function updateZone(array $body, array $stored = []): int
    {
        $permissionService = $this->createMock(ApiPermissionService::class);
        $permissionService->method('canEditZoneMeta')->willReturn(true);
        $permissionService->method('hasZoneContentEditPermission')->willReturn(true);
        $permissionService->method('canCreateZone')->willReturn(true);

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
        $zoneManagementService->method('updateZone')->willReturnCallback(function (int $id, array $updates): array {
            $this->written = $updates;
            return ['success' => true];
        });

        $controller = (new ReflectionClass(ZonesController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'permissionService', $permissionService);
        $this->inject($controller, 'zoneRepository', $zoneRepository);
        $this->inject($controller, 'zoneManagementService', $zoneManagementService);
        $this->inject($controller, 'request', new Request([], [], [], [], [], [], json_encode($body)));
        $this->inject($controller, 'pathParameters', ['id' => self::ZONE_ID]);
        $this->inject($controller, 'authenticatedUserId', 7);
        $this->inject($controller, 'ipAddressValidator', new IPAddressValidator());

        $response = (new ReflectionClass($controller))->getMethod('updateZone')->invoke($controller);

        return $response->getStatusCode();
    }

    private function inject(object $controller, string $property, mixed $value): void
    {
        (new ReflectionClass($controller))->getProperty($property)->setValue($controller, $value);
    }
}
