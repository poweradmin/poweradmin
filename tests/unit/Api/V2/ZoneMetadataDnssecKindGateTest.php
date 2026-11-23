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

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZoneMetadataController;
use Poweradmin\Application\Controller\EditZoneMetadataController;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\PermissionService;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Kinds that carry DNSSEC state need the DNSSEC permission on the zone besides metadata edit,
 * on the web editor and the API alike.
 */
class ZoneMetadataDnssecKindGateTest extends TestCase
{
    public function testDnssecStateKindsAreRecognisedInAnyCase(): void
    {
        foreach (['PRESIGNED', 'nsec3param', 'NSEC3NARROW', 'Publish-CDS', 'PUBLISH-CDNSKEY'] as $kind) {
            $this->assertTrue(MetadataDefinitions::isDnssecState($kind), $kind);
        }
        foreach (['SOA-EDIT', 'ALLOW-AXFR-FROM', 'X-CUSTOM'] as $kind) {
            $this->assertFalse(MetadataDefinitions::isDnssecState($kind), $kind);
        }
    }

    public static function writeMethods(): array
    {
        return [['updateMetadataKind'], ['deleteMetadataKind']];
    }

    #[DataProvider('writeMethods')]
    public function testApiRefusesDnssecKindWithoutDnssecPermission(string $method): void
    {
        foreach (['PRESIGNED', 'publish-cds'] as $kind) {
            $response = $this->callApi($method, $kind, canManageDnssec: false);
            $this->assertSame(403, $response->getStatusCode(), $kind);
            $this->assertStringContainsString('DNSSEC', (string)$response->getContent());
        }
    }

    #[DataProvider('writeMethods')]
    public function testApiDoesNotConsultDnssecPermissionForOrdinaryKinds(string $method): void
    {
        try {
            $this->callApi($method, 'SOA-EDIT', canManageDnssec: false, expectDnssecCheck: false);
        } catch (\Throwable) {
            // Past the gate the test double has no request body or storage; only the gate matters here
        }
    }

    public function testWebEditorRefusesDnssecKindWithoutDnssecPermission(): void
    {
        $this->assertSame([], $this->webViolations(['SOA-EDIT'], false));
        $this->assertCount(2, $this->webViolations(['PRESIGNED', 'NSEC3PARAM', 'SOA-EDIT'], false));
        $this->assertSame([], $this->webViolations(['PRESIGNED', 'NSEC3PARAM'], true));
    }

    private function callApi(string $method, string $kind, bool $canManageDnssec, bool $expectDnssecCheck = true): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $permissions->method('userHasPermission')->willReturn(false);
        $permissions->expects($expectDnssecCheck ? $this->atLeastOnce() : $this->never())
            ->method('canManageDnssec')->willReturn($canManageDnssec);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('zoneExists')->willReturn(true);

        $controller = new class extends ZoneMetadataController {
            public function __construct()
            {
            }

            protected function getApiKeyScope(): ApiKeyScope
            {
                return ApiKeyScope::unrestricted();
            }
        };
        $this->set(ZoneMetadataController::class, $controller, 'apiPermissionService', $permissions);
        $this->set(ZoneMetadataController::class, $controller, 'zoneRepository', $zones);
        $this->set(ZoneMetadataController::class, $controller, 'authenticatedUserId', 7);
        $this->set(ZoneMetadataController::class, $controller, 'pathParameters', ['id' => '3', 'kind' => $kind]);

        return (new ReflectionMethod(ZoneMetadataController::class, $method))->invoke($controller);
    }

    private function webViolations(array $kinds, bool $canManageDnssec): array
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canManageDnssecForZone')->willReturn($canManageDnssec);

        $controller = new class ($permissions) extends EditZoneMetadataController {
            public function __construct(private PermissionService $permissionStub, private ?int $userId = 7)
            {
            }

            protected function createPermissionService(): PermissionService
            {
                return $this->permissionStub;
            }

            protected function getCurrentUserId(): ?int
            {
                return $this->userId;
            }
        };
        $this->set(\Poweradmin\BaseController::class, $controller, 'db', $this->createMock(PDO::class));

        return (new ReflectionMethod(EditZoneMetadataController::class, 'dnssecStateViolations'))->invoke($controller, $kinds, 3);
    }

    private function set(string $class, object $object, string $name, mixed $value): void
    {
        (new ReflectionProperty($class, $name))->setValue($object, $value);
    }
}
