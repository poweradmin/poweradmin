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

    public function testRefusesAValueOverTheLengthCapUnlessItIsAlreadyStored(): void
    {
        $long = str_repeat('a', 1025);
        $this->assertSame(200, $this->put(0, 0, [str_repeat('a', 1024)])->getStatusCode());

        $over = $this->put(0, 0, [$long]);
        $this->assertSame(400, $over->getStatusCode());
        $this->assertStringContainsString('at most 1024 bytes', (string)$over->getContent());

        $this->assertSame(200, $this->put(0, 0, [$long], [['kind' => 'ALSO-NOTIFY', 'content' => $long]])->getStatusCode());
    }

    public function testRefusesANewKindOnAZoneAtTheKindCap(): void
    {
        $others = array_map(static fn(int $i): array => ['kind' => 'X-KIND-' . $i, 'content' => 'v'], range(1, 64));
        $over = $this->put(1, 0, null, $others);
        $this->assertSame(400, $over->getStatusCode());
        $this->assertStringContainsString('at most 64 metadata kinds', (string)$over->getContent());

        $sameKind = [...array_slice($others, 1), ['kind' => 'ALSO-NOTIFY', 'content' => '198.51.100.1']];
        $this->assertSame(200, $this->put(1, 0, null, $sameKind)->getStatusCode());
        $this->assertSame(200, $this->put(1, 0, null, array_slice($others, 1))->getStatusCode());
    }

    public function testARequestThatFailsTheValueChecksDoesNotLoadStoredMetadata(): void
    {
        $response = $this->put(0, 0, ['one', 'two'], [], 'SOA-EDIT-API', true);

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * @param array<int, string>|null $explicit
     * @param array<int, array{kind: string, content: string}> $extraStored
     */
    private function put(int $count, int $stored = 0, ?array $explicit = null, array $extraStored = [], string $kind = 'ALSO-NOTIFY', bool $loadThrows = false): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $permissions->method('userHasPermission')->willReturn(false);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('zoneExists')->willReturn(true);
        $zones->method('getDomainMetadata')->willReturn([...array_map(
            static fn(int $i): array => ['kind' => 'ALSO-NOTIFY', 'content' => '198.51.100.' . $i],
            $stored > 0 ? range(1, $stored) : []
        ), ...$extraStored]);
        if ($loadThrows) {
            $zones->method('getDomainMetadata')->willThrowException(new \RuntimeException('stored metadata unreachable'));
        }
        $zones->method('replaceDomainMetadata')->willReturn(true);

        $values = $explicit ?? array_map(static fn(int $i): string => '192.0.2.' . $i, range(1, $count));
        $request = Request::create('/api/v2/zones/3/metadata/' . $kind, 'PUT', [], [], [], [], json_encode(['values' => $values]));

        $controller = (new \ReflectionClass(ZoneMetadataController::class))->newInstanceWithoutConstructor();
        $this->set($controller, 'apiPermissionService', $permissions);
        $this->set($controller, 'zoneRepository', $zones);
        $this->set($controller, 'authenticatedUserId', 7);
        $this->set($controller, 'request', $request);
        $this->set($controller, 'pathParameters', ['id' => '3', 'kind' => $kind]);
        $this->set($controller, 'apiClient', null);

        return (new ReflectionMethod(ZoneMetadataController::class, 'updateMetadataKind'))->invoke($controller);
    }

    private function set(object $object, string $name, mixed $value): void
    {
        (new ReflectionProperty(ZoneMetadataController::class, $name))->setValue($object, $value);
    }
}
