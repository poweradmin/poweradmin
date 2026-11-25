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
use Poweradmin\Application\Controller\Api\V2\ZoneMetadataController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

/**
 * A single PUT may not write an unbounded list of values for one metadata kind.
 */
class ZoneMetadataValueCapTest extends TestCase
{
    public function testRefusesMoreThanTheCapAndAcceptsExactlyTheCap(): void
    {
        $over = $this->put(101);
        $this->assertSame(400, $over->getStatusCode());
        $this->assertStringContainsString('at most 100 values', (string)$over->getContent());

        $this->assertSame(200, $this->put(100)->getStatusCode());
    }

    public function testAKindStoredOverTheCapCanBeResavedOrShrunkButNotGrown(): void
    {
        $this->assertSame(200, $this->put(120, 120)->getStatusCode());
        $this->assertSame(200, $this->put(110, 120)->getStatusCode());
        $this->assertSame(400, $this->put(121, 120)->getStatusCode());
        $this->assertSame(400, $this->put(101, 50)->getStatusCode());
    }

    private function put(int $count, int $stored = 0): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $permissions->method('userHasPermission')->willReturn(false);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('zoneExists')->willReturn(true);
        $zones->method('getDomainMetadata')->willReturn(array_map(
            static fn(int $i): array => ['kind' => 'ALSO-NOTIFY', 'content' => '198.51.100.' . $i],
            $stored > 0 ? range(1, $stored) : []
        ));
        $zones->method('replaceDomainMetadata')->willReturn(true);

        $values = array_map(static fn(int $i): string => '192.0.2.' . $i, range(1, $count));
        $request = Request::create('/api/v2/zones/3/metadata/ALSO-NOTIFY', 'PUT', [], [], [], [], json_encode(['values' => $values]));

        $controller = (new \ReflectionClass(ZoneMetadataController::class))->newInstanceWithoutConstructor();
        $this->set($controller, 'apiPermissionService', $permissions);
        $this->set($controller, 'zoneRepository', $zones);
        $this->set($controller, 'authenticatedUserId', 7);
        $this->set($controller, 'request', $request);
        $this->set($controller, 'pathParameters', ['id' => '3', 'kind' => 'ALSO-NOTIFY']);
        $this->set($controller, 'apiClient', null);

        return (new ReflectionMethod(ZoneMetadataController::class, 'updateMetadataKind'))->invoke($controller);
    }

    private function set(object $object, string $name, mixed $value): void
    {
        (new ReflectionProperty(ZoneMetadataController::class, $name))->setValue($object, $value);
    }
}
