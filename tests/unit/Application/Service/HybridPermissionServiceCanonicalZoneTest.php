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

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use Poweradmin\Application\Service\HybridPermissionService;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * getDirectUserPermissions() joins zones to the permission templates, so an unresolved
 * domain_id silently drops every direct permission and the action is refused. This needs
 * the real perm_templ chain, hence the shared base class rather than a bare TestCase.
 */
class HybridPermissionServiceCanonicalZoneTest extends SqliteIntegrationTestCase
{
    private const EDITOR_TEMPL_ID = 20;
    private const EDITOR_USER_ID = 30;
    private const OTHER_USER_ID = 31;

    private HybridPermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (41, 'zone_content_edit_own')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::EDITOR_TEMPL_ID . ", 'Editor')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::EDITOR_TEMPL_ID . ", 41)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES
            (" . self::EDITOR_USER_ID . ", 'editor', " . self::EDITOR_TEMPL_ID . "),
            (" . self::OTHER_USER_ID . ", 'other', " . self::EDITOR_TEMPL_ID . ")");

        $this->service = new HybridPermissionService($this->db);
    }

    private function seedZone(int $id, ?int $domainId, int $owner): void
    {
        $stmt = $this->db->prepare("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (:id, :did, 'example.com', :owner)");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':did', $domainId, $domainId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':owner', $owner, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function testDirectPermissionsResolveForAZoneStrandedAtDomainIdZero(): void
    {
        $this->seedZone(55, 0, self::EDITOR_USER_ID);

        $result = $this->service->getUserPermissionsForZone(self::EDITOR_USER_ID, 55);

        $this->assertContains('zone_content_edit_own', $result['permissions']);
        $this->assertTrue($this->service->isUserZoneOwner(self::EDITOR_USER_ID, 55));
    }

    public function testDirectPermissionsResolveForANullDomainId(): void
    {
        $this->seedZone(56, null, self::EDITOR_USER_ID);

        $result = $this->service->getUserPermissionsForZone(self::EDITOR_USER_ID, 56);

        $this->assertContains('zone_content_edit_own', $result['permissions']);
    }

    public function testMigratedZoneResolvesByDomainIdOnly(): void
    {
        $this->seedZone(7, 201, self::EDITOR_USER_ID);

        $byDomainId = $this->service->getUserPermissionsForZone(self::EDITOR_USER_ID, 201);
        $this->assertContains('zone_content_edit_own', $byDomainId['permissions']);

        // The fallback must stay off when domain_id is populated, or the row would answer
        // to two identifiers.
        $byRowId = $this->service->getUserPermissionsForZone(self::EDITOR_USER_ID, 7);
        $this->assertSame([], $byRowId['permissions']);
    }

    public function testAnotherUsersStrandedZoneGrantsNothing(): void
    {
        $this->seedZone(55, 0, self::OTHER_USER_ID);

        $result = $this->service->getUserPermissionsForZone(self::EDITOR_USER_ID, 55);

        $this->assertSame([], $result['permissions']);
        $this->assertFalse($this->service->canUserPerformAction(self::EDITOR_USER_ID, 55, 'zone_content_edit_own'));
    }

    public function testAccessibleZonesReturnCanonicalIds(): void
    {
        $this->seedZone(55, 0, self::EDITOR_USER_ID);
        $this->seedZone(56, null, self::EDITOR_USER_ID);
        $this->seedZone(7, 201, self::EDITOR_USER_ID);

        $zones = $this->service->getUserAccessibleZones(self::EDITOR_USER_ID);
        $userZones = $zones['user_zones'];
        sort($userZones);

        $this->assertSame([55, 56, 201], $userZones);
        $this->assertSame([], $zones['group_zones']);
    }

    public function testGroupZonesAreUnaffectedByTheCanonicalResolution(): void
    {
        // zones_groups.domain_id already stores the canonical id, so that leg is untouched.
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (60, 'Editors', " . self::EDITOR_TEMPL_ID . ")");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::EDITOR_USER_ID . ", 60)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (401, 60)");

        $zones = $this->service->getUserAccessibleZones(self::EDITOR_USER_ID);

        $this->assertSame([401], $zones['group_zones']);
    }
}
