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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ApiPermissionService;

/**
 * userOwnsZone() gates canViewZone(), canEditZone() and the rest of the v2 API's "own"
 * scope. In API backend mode a zones row can carry domain_id NULL or 0, and the bare
 * column then matched nothing, so a user was silently denied a zone they own.
 */
class ApiPermissionServiceCanonicalZoneTest extends TestCase
{
    private PDO $db;
    private ApiPermissionService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)");
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)");

        $this->service = new ApiPermissionService($this->db);
    }

    private function seedZone(int $id, ?int $domainId, int $owner): void
    {
        $stmt = $this->db->prepare("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (:id, :did, 'example.com', :owner)");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':did', $domainId, $domainId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':owner', $owner, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function grant(int $userId, string $permission): void
    {
        $this->db->exec("INSERT OR IGNORE INTO perm_items (id, name) VALUES (1, '$permission')");
        $this->db->exec("INSERT OR IGNORE INTO perm_templ (id, name) VALUES (10, 'Tmpl')");
        $this->db->exec("INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (10, 1)");
        $this->db->exec("INSERT OR IGNORE INTO users (id, username, perm_templ) VALUES ($userId, 'u$userId', 10)");
    }

    public function testOwnershipResolvesForAZoneStrandedAtDomainIdZero(): void
    {
        $this->seedZone(55, 0, 1);

        $this->assertTrue($this->service->userOwnsZone(1, 55));
    }

    public function testOwnershipResolvesForANullDomainId(): void
    {
        $this->seedZone(56, null, 1);

        $this->assertTrue($this->service->userOwnsZone(1, 56));
    }

    public function testMigratedZoneResolvesByDomainIdOnly(): void
    {
        $this->seedZone(7, 201, 1);

        $this->assertTrue($this->service->userOwnsZone(1, 201));
        // The fallback must stay off when domain_id is populated.
        $this->assertFalse($this->service->userOwnsZone(1, 7));
    }

    public function testAnotherUsersZoneIsNotOwned(): void
    {
        $this->seedZone(55, 0, 2);

        $this->assertFalse($this->service->userOwnsZone(1, 55));
    }

    public function testGroupOwnershipStillResolves(): void
    {
        $this->seedZone(60, 60, 99);
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (60, 5)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (1, 5)");

        $this->assertTrue($this->service->userOwnsZone(1, 60));
    }

    public function testVisibleZoneIdsAreCanonicalIntegers(): void
    {
        $this->grant(1, 'zone_content_view_own');
        $this->seedZone(55, 0, 1);
        $this->seedZone(56, null, 1);
        $this->seedZone(7, 201, 1);

        $ids = $this->service->getUserVisibleZoneIds(1);
        sort($ids);

        $this->assertSame([55, 56, 201], $ids);
        $this->assertSame($ids, array_filter($ids, 'is_int'), 'a NULL domain_id used to leak through as null');
    }

    public function testNoViewPermissionYieldsNoZones(): void
    {
        $this->seedZone(55, 0, 1);

        $this->assertSame([], $this->service->getUserVisibleZoneIds(1));
    }
}
