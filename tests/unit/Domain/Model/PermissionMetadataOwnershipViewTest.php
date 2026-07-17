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
 * Covers Permission::getZoneMetadataViewPermission and
 * Permission::getZoneOwnershipViewPermission, the view scopes split out of
 * zone_content_view. Holders of zone_meta_edit_* keep view access to what
 * they can edit; plain content view no longer implies either scope.
 *
 * Each case runs in its own process because UserManager::verifyPermission caches
 * the resolved permission set in a function-static for the lifetime of a request.
 */
class PermissionMetadataOwnershipViewTest extends TestCase
{
    /**
     * Build a PDO mock whose permission query reports exactly the given permissions.
     *
     * @param string[] $permissions Permission names the current user holds.
     */
    private function dbWithPermissions(array $permissions): PDO
    {
        $grouped = [];
        foreach ($permissions as $name) {
            $grouped[$name] = [[]];
        }

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($grouped);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $_SESSION[SessionKeys::USERID] = 1;

        return $db;
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUeberuserSeesAllMetadataAndOwnership(): void
    {
        $db = $this->dbWithPermissions(['user_is_ueberuser']);

        $this->assertSame('all', Permission::getZoneMetadataViewPermission($db));
        $this->assertSame('all', Permission::getZoneOwnershipViewPermission($db));
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testViewOthersScopesAreIndependent(): void
    {
        $db = $this->dbWithPermissions(['zone_metadata_view_others']);

        $this->assertSame('all', Permission::getZoneMetadataViewPermission($db));
        $this->assertSame('none', Permission::getZoneOwnershipViewPermission($db));
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testViewOwnIsScopedToOwnedZones(): void
    {
        $db = $this->dbWithPermissions(['zone_metadata_view_own', 'zone_ownership_view_own']);

        $this->assertSame('own', Permission::getZoneMetadataViewPermission($db));
        $this->assertSame('own', Permission::getZoneOwnershipViewPermission($db));
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMetaEditOthersImpliesViewOfBoth(): void
    {
        $db = $this->dbWithPermissions(['zone_meta_edit_others']);

        $this->assertSame('all', Permission::getZoneMetadataViewPermission($db));
        $this->assertSame('all', Permission::getZoneOwnershipViewPermission($db));
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMetaEditOwnImpliesOwnViewOfBoth(): void
    {
        $db = $this->dbWithPermissions(['zone_meta_edit_own']);

        $this->assertSame('own', Permission::getZoneMetadataViewPermission($db));
        $this->assertSame('own', Permission::getZoneOwnershipViewPermission($db));
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testContentViewAloneYieldsNone(): void
    {
        $db = $this->dbWithPermissions(['zone_content_view_own', 'zone_content_view_others']);

        $this->assertSame('none', Permission::getZoneMetadataViewPermission($db));
        $this->assertSame('none', Permission::getZoneOwnershipViewPermission($db));
    }
}
