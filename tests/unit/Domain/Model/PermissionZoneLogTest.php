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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\SessionKeys;

/**
 * Covers Permission::getZoneLogPermission, which maps the dedicated zone log
 * permissions onto the "all"/"own"/"none" scope used by ListLogZonesController.
 *
 * Each case runs in its own process because PermissionService memoizes the
 * resolved permission set per user for the lifetime of a request.
 */
class PermissionZoneLogTest extends TestCase
{
    /**
     * Build a PDO mock whose permission queries report exactly the given permissions.
     * The admin-check query (filtered on 'user_is_ueberuser') and the full permission
     * list query are answered separately, matching DbUserRepository's two queries.
     *
     * @param string[] $permissions Permission names the current user holds.
     */
    private function dbWithPermissions(array $permissions): PDO
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $query) use ($permissions): PDOStatement {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);

            if (str_contains($query, "= 'user_is_ueberuser'")) {
                // Admin check: a single fetch() row when the user is ueberuser, else false
                $isAdmin = in_array('user_is_ueberuser', $permissions, true);
                $stmt->method('fetch')->willReturn($isAdmin ? ['permission' => 'user_is_ueberuser'] : false);
            } else {
                // Full permission list: one fetch() row per permission, then false
                $rows = array_map(static fn($name) => ['permission' => $name], $permissions);
                $rows[] = false;
                $stmt->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
            }

            return $stmt;
        });

        $_SESSION[SessionKeys::USERID] = 1;

        return $db;
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUeberuserSeesAllZoneLogs(): void
    {
        $db = $this->dbWithPermissions(['user_is_ueberuser']);

        $this->assertSame('all', Permission::getZoneLogPermission($db));
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testViewOthersSeesAllZoneLogs(): void
    {
        $db = $this->dbWithPermissions(['zone_logs_view_others']);

        $this->assertSame('all', Permission::getZoneLogPermission($db));
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testViewOwnIsScopedToOwnedZones(): void
    {
        $db = $this->dbWithPermissions(['zone_logs_view_own']);

        $this->assertSame('own', Permission::getZoneLogPermission($db));
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoLogPermissionYieldsNone(): void
    {
        $db = $this->dbWithPermissions(['zone_content_view_own', 'search']);

        $this->assertSame('none', Permission::getZoneLogPermission($db));
    }
}
