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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * userOwnsZone() is the ownership check behind BaseController::isZoneOwner(), so every zone
 * edit, delete and DNSSEC screen in the web UI resolves through it. In API backend mode a
 * zones row can carry domain_id NULL or 0, and the bare column then matched nothing, which
 * denied a user access to a zone they own.
 */
class DbUserRepositoryCanonicalZoneOwnershipTest extends TestCase
{
    private PDO $db;
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)");

        $this->repository = new DbUserRepository($this->db, ConfigurationManager::getInstance());
    }

    private function seedZone(int $id, ?int $domainId, int $owner, ?string $name = 'example.com'): void
    {
        $stmt = $this->db->prepare("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (:id, :did, :name, :owner)");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':did', $domainId, $domainId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':name', $name, $name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':owner', $owner, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function testResolvesAZoneStrandedAtDomainIdZero(): void
    {
        $this->seedZone(55, 0, 1);

        $this->assertTrue($this->repository->userOwnsZone(1, 55));
    }

    public function testResolvesAZoneWithNullDomainId(): void
    {
        $this->seedZone(56, null, 1);

        $this->assertTrue($this->repository->userOwnsZone(1, 56));
    }

    public function testMigratedZoneStillResolvesByDomainId(): void
    {
        $this->seedZone(7, 201, 1);

        $this->assertTrue($this->repository->userOwnsZone(1, 201));
    }

    public function testMigratedZoneIsNotReachableByItsRowId(): void
    {
        // The fallback must not fire when domain_id is populated, or one zone would answer
        // to two identifiers and collide with whatever else owns that id.
        $this->seedZone(7, 201, 1);

        $this->assertFalse($this->repository->userOwnsZone(1, 7));
    }

    public function testAnotherUsersZoneIsNotOwned(): void
    {
        $this->seedZone(55, 0, 2);

        $this->assertFalse($this->repository->userOwnsZone(1, 55));
    }

    public function testGroupOwnershipStillResolves(): void
    {
        $this->seedZone(60, 60, 99);
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (60, 5)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (1, 5)");

        $this->assertTrue($this->repository->userOwnsZone(1, 60));
    }

    /**
     * SQL backend mode never stores a NULL or 0 domain_id, so the canonical fallback must
     * never fire there. This pins the whole ownership matrix of a SQL-mode fixture, since
     * a wrong fallback would hand a user someone else's zone.
     */
    public function testSqlModeOwnershipMatrixIsUnchanged(): void
    {
        // zones.id and zones.domain_id occupy overlapping spaces on a migrated install:
        // zone id 3 shares its primary key with zone 1's domain_id.
        $this->seedZone(1, 3, 10, null);
        $this->seedZone(2, 4, 20, null);
        $this->seedZone(3, 5, 30, null);

        $expected = [
            [10, 3, true], [10, 4, false], [10, 5, false], [10, 1, false],
            [20, 4, true], [20, 3, false], [20, 5, false], [20, 2, false],
            [30, 5, true], [30, 3, false], [30, 4, false],
        ];

        foreach ($expected as [$userId, $zoneId, $owns]) {
            $this->assertSame(
                $owns,
                $this->repository->userOwnsZone($userId, $zoneId),
                "user $userId vs zone $zoneId"
            );
        }
    }
}
