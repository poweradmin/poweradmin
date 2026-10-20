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
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use TestHelpers\SqliteIntegrationTestCase;

#[CoversClass(DbUserGroupRepository::class)]
class DbUserGroupRepositoryTest extends SqliteIntegrationTestCase
{
    private DbUserGroupRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        // The shared harness only carries the columns the permission query needs
        $this->db->exec("ALTER TABLE user_groups ADD COLUMN description TEXT");
        $this->db->exec("ALTER TABLE user_groups ADD COLUMN created_by INTEGER");
        $this->db->exec("ALTER TABLE user_groups ADD COLUMN created_at TEXT");
        $this->db->exec("ALTER TABLE user_groups ADD COLUMN updated_at TEXT");
        $this->db->exec("INSERT INTO user_groups (id, name, description, perm_templ) VALUES
            (3, 'Editors', 'Zone editors', " . self::ADMIN_PERM_TEMPL_ID . "),
            (8, 'Operators', NULL, " . self::ADMIN_PERM_TEMPL_ID . ")");

        $this->repository = new DbUserGroupRepository($this->db);
    }

    #[Test]
    public function findExistingIdsReturnsEmptyForEmptyInputWithoutQuerying(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');

        $this->assertSame([], (new DbUserGroupRepository($db))->findExistingIds([]));
    }

    #[Test]
    public function findExistingIdsKeepsOnlyTheIdsThatExist(): void
    {
        $this->assertSame([3, 8], $this->repository->findExistingIds([3, 5, 8]));
    }

    #[Test]
    public function findExistingIdsHandlesASingleId(): void
    {
        $this->assertSame([3], $this->repository->findExistingIds([3]));
    }

    #[Test]
    public function findExistingIdsReturnsIntegers(): void
    {
        foreach ($this->repository->findExistingIds([3, 8]) as $value) {
            $this->assertIsInt($value);
        }
    }

    #[Test]
    public function findExistingIdsReturnsEmptyArrayWhenNoneExist(): void
    {
        $this->assertSame([], $this->repository->findExistingIds([1, 2]));
    }

    #[Test]
    public function findIdByExactNameMatchesTheStoredSpelling(): void
    {
        $this->assertSame(3, $this->repository->findIdByExactName('Editors'));
    }

    #[Test]
    public function findIdByExactNameReturnsNullWhenNoGroupMatches(): void
    {
        $this->assertNull($this->repository->findIdByExactName('Nobody'));
    }

    #[Test]
    public function findByUserIdReturnsTheGroupsAUserBelongsToByName(): void
    {
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES
            (" . self::ADMIN_USER_ID . ", 8), (" . self::ADMIN_USER_ID . ", 3)");

        $names = array_map(
            fn($group): string => $group->getName(),
            $this->repository->findByUserId(self::ADMIN_USER_ID)
        );

        $this->assertSame(['Editors', 'Operators'], $names);
        $this->assertSame([3, 8], $this->repository->getGroupIdsForUser(self::ADMIN_USER_ID));
    }

    #[Test]
    public function memberCountsAreGroupedByGroupId(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (20, 'mia', 1)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES
            (" . self::ADMIN_USER_ID . ", 3), (20, 3), (20, 8)");

        $this->assertSame([3 => 2, 8 => 1], $this->repository->getMemberCountsByGroupIds([3, 8]));
    }

    /**
     * The accent-sensitive comparison is a MySQL collation cast that no other
     * driver emits, so it can only be observed in the prepared SQL.
     */
    #[Test]
    public function findIdByExactNameUsesAnAccentSensitiveMatchOnMysql(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('execute')->with(['Editors'])->willReturn(true);
        $statement->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn(['id' => '3']);

        $db = $this->createMock(PDO::class);
        $db->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $db->expects($this->once())
            ->method('prepare')
            ->with($this->matchesRegularExpression('/SELECT id FROM user_groups WHERE LOWER\(CONVERT\(name USING utf8mb4\)\) COLLATE utf8mb4_bin = LOWER\(\?\)/'))
            ->willReturn($statement);

        $this->assertSame(3, (new DbUserGroupRepository($db))->findIdByExactName('Editors'));
    }
}
