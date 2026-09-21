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

namespace Poweradmin\Tests\Unit\Application\Controller\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\Template\ListZoneTemplController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateSyncRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Infrastructure\Session\SessionActor;
use Poweradmin\Domain\Service\Template\ZoneTemplateAccessPolicy;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Tests\Unit\Application\Controller\ControllerHalt;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Characterizes what the zone template list hands its template for a ueberuser
 * and for a template owner holding zone_templ_edit, so the per-row action
 * decision matches the rule the edit and delete pages apply.
 */
#[CoversClass(ListZoneTemplController::class)]
class ListZoneTemplControllerTest extends SeamControllerTestCase
{
    private const OWN_ID = 10;
    private const FOREIGN_ID = 11;
    private const GLOBAL_ID = 12;

    /** @var list<string> Permissions hasPermission() answers yes to */
    private array $granted = [];

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')
            ->willReturnCallback(fn(int $userId, string $permission): bool => in_array($permission, $this->granted, true));

        $rows = [
            $this->row(self::OWN_ID, self::USER_ID, false, 2),
            $this->row(self::FOREIGN_ID, 7, false, 0),
            $this->row(self::GLOBAL_ID, 0, true, 0),
        ];
        $templates = $this->createMock(ZoneTemplateService::class);
        $templates->method('getListZoneTempl')->willReturn($rows);
        $templates->method('getDefaultTemplateId')->willReturn(self::GLOBAL_ID);

        $sync = $this->createMock(ZoneTemplateSyncRepositoryInterface::class);
        $sync->method('getTemplateSyncStatus')->willReturn([]);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getFullNameById')->willReturn('Test User');

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('zoneTemplateService')->willReturn($templates);
        $this->factory->method('zoneTemplateAccessPolicy')->willReturn(
            new ZoneTemplateAccessPolicy($this->createMock(ZoneTemplateRepositoryInterface::class), $permissions, new SessionActor())
        );
        $this->factory->method('zoneTemplateSync')->willReturn($sync);
        $this->factory->method('userRepository')->willReturn($users);
    }

    /** @return array<string, mixed> */
    private function row(int $id, int $owner, bool $isDefault, int $zonesLinked): array
    {
        return ['id' => $id, 'name' => 't' . $id, 'descr' => '', 'owner' => (string)$owner, 'is_default' => $isDefault, 'zones_linked' => (string)$zonesLinked];
    }

    /** @return array<string, mixed> */
    private function renderedParams(): array
    {
        $controller = new TestableListZoneTemplController([], $this->environment($this->configure()));
        $controller->run();

        $this->assertSame('list_zone_templ.html', $controller->rendered[0][0]);

        return $controller->renderedParams();
    }

    public function testWithoutAnyTemplatePermissionThePageIsRefused(): void
    {
        $controller = new TestableListZoneTemplController([], $this->environment($this->configure()));

        try {
            $controller->run();
            $this->fail('expected a halt');
        } catch (ControllerHalt $halt) {
            $this->assertSame(ControllerHalt::KIND_CONDITION, $halt->kind);
            $this->assertSame('You do not have permission to view zone templates.', $halt->target);
        }
    }

    public function testUeberuserGetsTheRawFlags(): void
    {
        $this->granted = [Permission::PERM_USER_IS_UEBERUSER];

        $params = $this->renderedParams();

        $this->assertTrue($params['perm_is_godlike']);
        $this->assertFalse($params['perm_zone_templ_edit']);
        $this->assertFalse($params['perm_zone_templ_add']);
        $this->assertSame(self::GLOBAL_ID, $params['effective_default_id']);
        $this->assertTrue($params['has_db_default']);
        $this->assertSame('Test User', $params['user_name']);
        $this->assertSame([self::OWN_ID, self::FOREIGN_ID, self::GLOBAL_ID], array_column($params['zone_templates'], 'id'));
        $this->assertSame([true, true, true], array_column($params['zone_templates'], 'can_edit'));
        // Only global or already-default templates take the set/unset default button
        $this->assertSame([false, false, true], array_column($params['zone_templates'], 'can_set_default'));
        $this->assertSame([true, false, false], array_column($params['zone_templates'], 'has_zones'));
    }

    public function testTemplateOwnerWithEditPermissionGetsTheRawFlags(): void
    {
        $this->granted = [Permission::PERM_ZONE_TEMPL_EDIT];

        $params = $this->renderedParams();

        $this->assertFalse($params['perm_is_godlike']);
        $this->assertTrue($params['perm_zone_templ_edit']);
        $this->assertCount(3, $params['zone_templates']);
        // Another user's template and the global one are refused by the edit page
        $this->assertSame([true, false, false], array_column($params['zone_templates'], 'can_edit'));
        $this->assertSame([false, false, false], array_column($params['zone_templates'], 'can_set_default'));
    }

    public function testAddPermissionAloneGivesNoRowActions(): void
    {
        $this->granted = [Permission::PERM_ZONE_TEMPL_ADD];

        $params = $this->renderedParams();

        $this->assertSame([false, false, false], array_column($params['zone_templates'], 'can_edit'));
    }
}
