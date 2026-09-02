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
use Poweradmin\Application\Controller\Api\V2\ZoneTemplateRecordsController;
use Poweradmin\Application\Controller\Api\V2\ZoneTemplatesController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use ReflectionClass;

/**
 * The web zone-template list refuses a user holding no zone_templ_* permission.
 * The API read paths must refuse the same user, including on global templates,
 * which were previously readable by any authenticated caller. Zone creators are
 * the exception: the web add-zone form offers them templates, so they may read.
 *
 * These drive the real private predicates by reflection rather than a copy, so
 * the test fails if the guard is removed from the controller itself.
 */
class ZoneTemplateReadPermissionTest extends TestCase
{
    private const GLOBAL_TEMPLATE_ID = 1;
    private const USER_ID = 7;

    public function testListGateRefusesUserWithoutZoneTemplatePermission(): void
    {
        $this->assertFalse($this->canViewZoneTemplates([]));
    }

    public function testListGateAllowsZoneTemplAdd(): void
    {
        $this->assertTrue($this->canViewZoneTemplates(['zone_templ_add']));
    }

    public function testListGateAllowsZoneTemplEdit(): void
    {
        $this->assertTrue($this->canViewZoneTemplates(['zone_templ_edit']));
    }

    public function testListGateAllowsUeberuser(): void
    {
        $this->assertTrue($this->canViewZoneTemplates(['user_is_ueberuser']));
    }

    public function testListGateAllowsZoneMasterAdd(): void
    {
        $this->assertTrue($this->canViewZoneTemplates(['zone_master_add']));
    }

    public function testListGateAllowsZoneSlaveAdd(): void
    {
        $this->assertTrue($this->canViewZoneTemplates(['zone_slave_add']));
    }

    public function testGlobalTemplateRecordsRefusedWithoutZoneTemplatePermission(): void
    {
        $this->assertFalse($this->canViewTemplate([], 0));
    }

    public function testGlobalTemplateRecordsAllowedWithZoneTemplatePermission(): void
    {
        $this->assertTrue($this->canViewTemplate(['zone_templ_edit'], 0));
    }

    public function testGlobalTemplateRecordsAllowedWithZoneMasterAdd(): void
    {
        $this->assertTrue($this->canViewTemplate(['zone_master_add'], 0));
    }

    public function testForeignTemplateRecordsRefusedForZoneCreator(): void
    {
        $this->assertFalse($this->canViewTemplate(['zone_master_add'], 99, false));
    }

    public function testForeignTemplateRecordsRefusedEvenWithZoneTemplatePermission(): void
    {
        $this->assertFalse($this->canViewTemplate(['zone_templ_edit'], 99, false));
    }

    public function testOwnTemplateRecordsAllowed(): void
    {
        $this->assertTrue($this->canViewTemplate(['zone_templ_edit'], self::USER_ID, true));
    }

    /**
     * @param string[] $granted
     */
    private function canViewZoneTemplates(array $granted): bool
    {
        $controller = (new ReflectionClass(ZoneTemplatesController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'apiPermissionService', $this->permissionService($granted));

        return (bool)$this->call($controller, 'canViewZoneTemplates', [self::USER_ID]);
    }

    /**
     * @param string[] $granted
     */
    private function canViewTemplate(array $granted, int $owner, bool $isOwner = false): bool
    {
        $repository = $this->createMock(DbZoneTemplateRepository::class);
        $repository->method('getOwner')->willReturn($owner);
        $repository->method('isOwner')->willReturn($isOwner);

        $controller = (new ReflectionClass(ZoneTemplateRecordsController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'apiPermissionService', $this->permissionService($granted));
        $this->inject($controller, 'repository', $repository);

        return (bool)$this->call($controller, 'canViewTemplate', [self::USER_ID, self::GLOBAL_TEMPLATE_ID]);
    }

    /**
     * @param string[] $granted
     */
    private function permissionService(array $granted): ApiPermissionService
    {
        $service = $this->createMock(ApiPermissionService::class);
        $service->method('userHasPermission')
            ->willReturnCallback(fn(int $userId, string $permission): bool => in_array($permission, $granted, true));

        return $service;
    }

    private function inject(object $controller, string $property, object $value): void
    {
        $reflection = new ReflectionClass($controller);
        $reflection->getProperty($property)->setValue($controller, $value);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function call(object $controller, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionClass($controller);

        return $reflection->getMethod($method)->invokeArgs($controller, $arguments);
    }
}
