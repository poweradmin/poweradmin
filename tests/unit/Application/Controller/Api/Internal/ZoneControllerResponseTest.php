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
 */

namespace Poweradmin\Tests\Unit\Application\Controller\Api\Internal;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\Internal\ZoneController;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The internal /api/internal/zone handlers previously built responses but never
 * returned or sent them, so every call produced an empty 200. They must now
 * return a JsonResponse that run() sends (audit H8).
 */
class ZoneControllerResponseTest extends TestCase
{
    public function testGetZoneReturnsJsonResponseForInvalidId(): void
    {
        $controller = (new ReflectionClass(ZoneController::class))->newInstanceWithoutConstructor();

        // id=0 is rejected before any permission/repository access, so no other
        // collaborators need to be wired up.
        $request = Request::create('/api/internal/zone?action=get&id=0');
        $property = new ReflectionProperty($controller, 'request');
        $property->setValue($controller, $request);

        $method = new ReflectionMethod($controller, 'getZone');
        $response = $method->invoke($controller);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('invalid zone ID', (string)$response->getContent());
    }

    /**
     * @param string[] $permissions
     */
    private function controllerHolding(array $permissions): ZoneController
    {
        return new class ($permissions) extends ZoneController {
            /** @param string[] $granted */
            public function __construct(private array $granted)
            {
            }

            protected function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->granted, true);
            }
        };
    }

    private function invokeWith(ZoneController $controller, string $method, string $uri): JsonResponse
    {
        $property = new ReflectionProperty($controller, 'request');
        $property->setValue($controller, Request::create($uri));

        $reflected = new ReflectionMethod($controller, $method);

        return $reflected->invoke($controller);
    }

    public function testGetZoneRefusesASessionHoldingNeitherViewPermission(): void
    {
        $response = $this->invokeWith(
            $this->controllerHolding([]),
            'getZone',
            '/api/internal/zone?action=get&id=5'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testListZonesRefusesASessionHoldingNeitherViewPermission(): void
    {
        $response = $this->invokeWith(
            $this->controllerHolding([]),
            'listZones',
            '/api/internal/zone?action=list'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testGetZoneAcceptsOwnViewPermissionAlone(): void
    {
        // Past the gate it reaches the ownership lookup, which needs collaborators this
        // seam does not wire up; not being a 403 is what this pins.
        $this->expectException(\Error::class);

        $this->invokeWith(
            $this->controllerHolding(['zone_content_view_own']),
            'getZone',
            '/api/internal/zone?action=get&id=5'
        );
    }

    /**
     * @param int[] $owned
     */
    private function controllerWithOwnershipView(string $level, array $owned, array $rows, ?array $detail = null): ZoneController
    {
        $controller = new class ($level, $owned) extends ZoneController {
            /** @param int[] $owned */
            public function __construct(private string $level, private array $owned)
            {
            }

            protected function hasPermission(string $permission): bool
            {
                return true;
            }

            protected function ownershipViewLevel(int $userId): string
            {
                return $this->level;
            }

            protected function userOwnsZoneId(int $userId, int $zoneId): bool
            {
                return in_array($zoneId, $this->owned, true);
            }
        };

        $repository = $this->createMock(\Poweradmin\Domain\Repository\ZoneRepositoryInterface::class);
        $repository->method('listZones')->willReturn($rows);
        $repository->method('zoneExists')->willReturn(true);
        $repository->method('getZone')->willReturn($detail);

        $ref = new ReflectionClass(ZoneController::class);
        $ref->getProperty('zoneRepository')->setValue($controller, $repository);
        $ref->getProperty('userContextService')->setValue($controller, new class extends \Poweradmin\Domain\Service\UserContextService {
            public function __construct()
            {
            }

            public function getLoggedInUserId(): int
            {
                return 7;
            }
        });

        return $controller;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $row = fn(int $id) => ['id' => $id, 'name' => "z$id.example", 'type' => 'MASTER', 'username' => 'alice', 'fullname' => 'Alice A'];

        return [$row(1), $row(2)];
    }

    /** @return list<array<string, mixed>> */
    private function listed(ZoneController $controller): array
    {
        return json_decode((string)$this->invokeWith($controller, 'listZones', '/x?action=list')->getContent(), true)['data']['zones'];
    }

    /** @return array<string, mixed> */
    private function detail(): array
    {
        return ['id' => 2, 'name' => 'z2.example', 'username' => 'alice', 'fullname' => 'Alice A', 'owners' => ['alice'], 'full_names' => ['Alice A'], 'owner' => 9];
    }

    public function testListZonesHidesOwnersWhenOwnershipViewIsNone(): void
    {
        foreach ($this->listed($this->controllerWithOwnershipView('none', [], $this->rows())) as $zone) {
            $this->assertArrayHasKey('username', $zone);
            $this->assertNull($zone['username']);
            $this->assertNull($zone['fullname']);
        }
    }

    public function testListZonesShowsOwnersOnlyOnOwnedZonesAtOwnLevel(): void
    {
        $zones = $this->listed($this->controllerWithOwnershipView('own', [1], $this->rows()));

        $this->assertSame('alice', $zones[0]['username']);
        $this->assertNull($zones[1]['username']);
    }

    public function testListZonesKeepsOwnersAtAllLevel(): void
    {
        $zones = $this->listed($this->controllerWithOwnershipView('all', [], $this->rows()));

        $this->assertSame('alice', $zones[1]['username']);
    }

    public function testGetZoneHidesOwnerIdentityWhenOwnershipViewIsNone(): void
    {
        $controller = $this->controllerWithOwnershipView('none', [], [], $this->detail());
        $zone = json_decode((string)$this->invokeWith($controller, 'getZone', '/x?action=get&id=2')->getContent(), true)['data']['zone'];

        $this->assertNull($zone['username']);
        $this->assertNull($zone['fullname']);
        $this->assertSame([], $zone['owners']);
        $this->assertSame([], $zone['full_names']);
        $this->assertSame(0, $zone['owner']);
        $this->assertSame('z2.example', $zone['name']);
    }

    public function testGetZoneShowsOwnersOnOwnedZoneAtOwnLevel(): void
    {
        $controller = $this->controllerWithOwnershipView('own', [2], [], $this->detail());
        $zone = json_decode((string)$this->invokeWith($controller, 'getZone', '/x?action=get&id=2')->getContent(), true)['data']['zone'];

        $this->assertSame('alice', $zone['username']);
        $this->assertSame(['alice'], $zone['owners']);
    }
}
