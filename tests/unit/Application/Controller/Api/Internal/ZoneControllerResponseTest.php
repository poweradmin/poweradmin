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
use Poweradmin\Domain\Model\Permission;
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
            $this->controllerHolding([Permission::PERM_ZONE_CONTENT_VIEW_OWN]),
            'getZone',
            '/api/internal/zone?action=get&id=5'
        );
    }

    /**
     * @param string[] $owned
     */
    private function controllerWithOwnershipView(string $level, array $owned, object $repository): ZoneController
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

            protected function ownedZoneIds(int $userId, array $zoneIds): array
            {
                return array_values(array_intersect(array_map('intval', $zoneIds), $this->owned));
            }

            protected function isZoneOwner(int $zoneId): bool
            {
                return in_array($zoneId, $this->owned, true);
            }
        };

        $ref = new ReflectionClass(ZoneController::class);
        $ref->getProperty('zoneRepository')->setValue($controller, $repository);
        $ref->getProperty('userContextService')->setValue($controller, new class extends \Poweradmin\Domain\Service\Auth\UserContextService {
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

    private function repositoryWithZones(): object
    {
        $row = fn(int $id) => ['id' => $id, 'name' => "z$id.example", 'type' => 'MASTER', 'username' => 'alice', 'fullname' => 'Alice A'];
        $detail = new \Poweradmin\Domain\Model\ZoneDetail(2, 'z2.example', 'z2.example', 'MASTER', null, null, 9, '', 0, false, ['alice'], ['Alice A']);

        return new class ([$row(1), $row(2)], $detail) extends \Poweradmin\Infrastructure\Repository\DbZoneRepository {
            public function __construct(private array $rows, private \Poweradmin\Domain\Model\ZoneDetail $detail)
            {
            }

            public function listZones(?int $userId = null, bool $viewOthers = false, array $filters = [], int $offset = 0, int $limit = 100): array
            {
                return $this->rows;
            }

            public function getZone(int $zoneId): \Poweradmin\Domain\Model\ZoneDetail
            {
                return $this->detail;
            }
        };
    }

    public function testListZonesHidesOwnersWhenOwnershipViewIsNone(): void
    {
        $controller = $this->controllerWithOwnershipView('none', [], $this->repositoryWithZones());
        $data = json_decode((string)$this->invokeWith($controller, 'listZones', '/x?action=list')->getContent(), true);

        foreach ($data['data']['zones'] as $zone) {
            $this->assertNull($zone['username']);
            $this->assertNull($zone['fullname']);
        }
    }

    public function testListZonesShowsOwnersOnlyOnOwnedZonesAtOwnLevel(): void
    {
        $controller = $this->controllerWithOwnershipView('own', [1], $this->repositoryWithZones());
        $zones = json_decode((string)$this->invokeWith($controller, 'listZones', '/x?action=list')->getContent(), true)['data']['zones'];

        $this->assertSame('alice', $zones[0]['username']);
        $this->assertNull($zones[1]['username']);
    }

    public function testListZonesKeepsOwnersAtAllLevel(): void
    {
        $controller = $this->controllerWithOwnershipView('all', [], $this->repositoryWithZones());
        $zones = json_decode((string)$this->invokeWith($controller, 'listZones', '/x?action=list')->getContent(), true)['data']['zones'];

        $this->assertSame('alice', $zones[1]['username']);
    }

    public function testGetZoneHidesOwnerIdentityWhenOwnershipViewIsNone(): void
    {
        $controller = $this->controllerWithOwnershipView('none', [], $this->repositoryWithZones());
        $zone = json_decode((string)$this->invokeWith($controller, 'getZone', '/x?action=get&id=2')->getContent(), true)['data']['zone'];

        $this->assertNull($zone['username']);
        $this->assertNull($zone['fullname']);
        $this->assertSame([], $zone['owners']);
        $this->assertSame([], $zone['full_names']);
        $this->assertSame([], $zone['users']);
        $this->assertSame(0, $zone['owner']);
        $this->assertSame('z2.example', $zone['name']);
    }

    public function testGetZoneShowsOwnersOnOwnedZoneAtOwnLevel(): void
    {
        $controller = $this->controllerWithOwnershipView('own', [2], $this->repositoryWithZones());
        $zone = json_decode((string)$this->invokeWith($controller, 'getZone', '/x?action=get&id=2')->getContent(), true)['data']['zone'];

        $this->assertSame('alice', $zone['username']);
        $this->assertSame(['alice'], $zone['owners']);
    }
}
