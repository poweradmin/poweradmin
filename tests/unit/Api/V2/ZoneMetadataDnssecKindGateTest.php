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
use Poweradmin\Application\Controller\Api\V2\ZoneMetadataController;
use Poweradmin\Application\Controller\EditZoneMetadataController;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Kinds that carry DNSSEC state need the right that gates DNSSEC key management (content edit)
 * besides metadata edit, on the web editor and the API alike.
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
    public function testApiRefusesDnssecKindWithoutContentEdit(string $method): void
    {
        $response = $this->callApi($method, 'publish-cds', canEditZone: false);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('DNSSEC', (string)$response->getContent());
    }

    #[DataProvider('writeMethods')]
    public function testReadOnlyKindsKeepTheirReadOnlyMessage(string $method): void
    {
        $response = $this->callApi($method, 'PRESIGNED', canEditZone: false, expectCheck: false);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('is read-only', (string)$response->getContent());
    }

    #[DataProvider('writeMethods')]
    public function testApiDoesNotConsultContentEditForOrdinaryKinds(string $method): void
    {
        try {
            $this->callApi($method, 'SOA-EDIT', canEditZone: false, expectCheck: false);
        } catch (\Throwable) {
            // Past the gate the test double has no request body or storage; only the gate matters here
        }
    }

    public function testWebEditorComparesDnssecKindsAgainstStoredRows(): void
    {
        $stored = [['kind' => 'PRESIGNED', 'content' => '1'], ['kind' => 'SOA-EDIT', 'content' => 'INCEPTION']];

        // Unchanged stored DNSSEC row plus an edit to an ordinary kind is fine without the right
        $this->assertSame([], $this->webViolations([['kind' => 'PRESIGNED', 'content' => '1'], ['kind' => 'SOA-EDIT', 'content' => 'EPOCH']], $stored, false));
        // Removing it, adding NSEC3PARAM, or changing it is refused
        $this->assertCount(1, $this->webViolations([['kind' => 'SOA-EDIT', 'content' => 'INCEPTION']], $stored, false));
        $this->assertCount(2, $this->webViolations([['kind' => 'presigned', 'content' => '0'], ['kind' => 'NSEC3PARAM', 'content' => '1 0 0 -']], $stored, false));
        // Holding the right changes nothing and never loads the stored rows
        $this->assertSame([], $this->webViolations([['kind' => 'PRESIGNED', 'content' => '0']], $stored, true));
    }

    private function callApi(string $method, string $kind, bool $canEditZone, bool $expectCheck = true): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $permissions->method('userHasPermission')->willReturn(false);
        $permissions->expects($expectCheck ? $this->atLeastOnce() : $this->never())
            ->method('canEditZone')->willReturn($canEditZone);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('zoneExists')->willReturn(true);

        $controller = (new \ReflectionClass(ZoneMetadataController::class))->newInstanceWithoutConstructor();
        $this->set(ZoneMetadataController::class, $controller, 'apiPermissionService', $permissions);
        $this->set(ZoneMetadataController::class, $controller, 'zoneRepository', $zones);
        $this->set(ZoneMetadataController::class, $controller, 'authenticatedUserId', 7);
        $this->set(ZoneMetadataController::class, $controller, 'pathParameters', ['id' => '3', 'kind' => $kind]);

        return (new ReflectionMethod(ZoneMetadataController::class, $method))->invoke($controller);
    }

    private function webViolations(array $submitted, array $stored, bool $canManage): array
    {
        $controller = new class ($canManage) extends EditZoneMetadataController {
            public function __construct(private bool $canManage)
            {
            }

            protected function canManageDnssecState(int $zoneId): bool
            {
                return $this->canManage;
            }
        };

        $load = function () use ($stored, $canManage): array {
            $this->assertFalse($canManage, 'Stored rows are only needed when the caller lacks the right');
            return $stored;
        };

        return (new ReflectionMethod(EditZoneMetadataController::class, 'dnssecStateViolations'))->invoke($controller, $submitted, $load, 3);
    }

    private function set(string $class, object $object, string $name, mixed $value): void
    {
        (new ReflectionProperty($class, $name))->setValue($object, $value);
    }
}
