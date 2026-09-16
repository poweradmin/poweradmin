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

namespace Poweradmin\Tests\Unit\Infrastructure\Web;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Web\NavigationVisibility;
use TestHelpers\FakeConfiguration;

class NavigationVisibilityTest extends TestCase
{
    /**
     * @param list<string> $granted
     * @param array<string, array<string, mixed>> $config
     * @return array<string, bool>
     */
    private function build(array $granted, array $config = [], bool $hasModuleItems = false): array
    {
        return NavigationVisibility::build(
            static fn(string $permission): bool => in_array($permission, $granted, true),
            new FakeConfiguration($config),
            $hasModuleItems
        );
    }

    public function testNothingGrantedHidesEveryUserEntry(): void
    {
        $nav = $this->build([]);

        $this->assertSame([], array_keys(array_filter($nav)));
    }

    public function testZoneMenuFollowsAnyZoneGrant(): void
    {
        $this->assertTrue($this->build(['zone_slave_add'])['zones']);
        $this->assertFalse($this->build(['zone_slave_add'])['zone_list']);
        $this->assertTrue($this->build(['zone_content_view_own'])['zone_list']);
        // Log grants alone open the menu only when database logging is on.
        $this->assertFalse($this->build(['zone_logs_view_own'])['zones']);
        $this->assertTrue($this->build(['zone_logs_view_own'], ['logging' => ['database_enabled' => true]])['zones']);
    }

    public function testLogEntriesNeedDatabaseLogging(): void
    {
        $off = $this->build(['user_is_ueberuser']);
        $on = $this->build(['user_is_ueberuser'], ['logging' => ['database_enabled' => true]]);

        $this->assertFalse($off['zone_logs']);
        $this->assertFalse($off['record_changes']);
        $this->assertFalse($off['user_logs']);
        $this->assertFalse($off['group_logs']);
        $this->assertTrue($on['zone_logs']);
        $this->assertTrue($on['record_changes']);
        $this->assertTrue($on['user_logs']);
        $this->assertTrue($on['group_logs']);
    }

    public function testRecordChangesAreForTheUeberuserOnly(): void
    {
        $nav = $this->build(['zone_logs_view_others'], ['logging' => ['database_enabled' => true]]);

        $this->assertTrue($nav['zone_logs']);
        $this->assertFalse($nav['record_changes']);
    }

    public function testGroupsMenuHonoursTheDisplaySetting(): void
    {
        $this->assertTrue($this->build(['user_is_ueberuser'])['groups']);
        $this->assertFalse($this->build(['user_is_ueberuser'], ['permissions' => ['show_group_access_templates' => false]])['groups']);
    }

    public function testToolsMenuOpensForApiKeysConsistencyOrModules(): void
    {
        $this->assertFalse($this->build(['api_manage_keys'])['tools']);
        $this->assertTrue($this->build(['api_manage_keys'], ['api' => ['enabled' => true]])['tools']);
        $this->assertTrue($this->build(['user_is_ueberuser'], ['interface' => ['enable_consistency_checks' => true]])['database_consistency']);
        $this->assertTrue($this->build([], [], true)['tools']);
    }

    public function testApiDocsFollowTheApiAndDocsSettings(): void
    {
        $this->assertFalse($this->build([], ['api' => ['enabled' => true]])['api_docs']);
        $this->assertTrue($this->build([], ['api' => ['enabled' => true, 'docs_enabled' => true]])['api_docs']);
    }
}
