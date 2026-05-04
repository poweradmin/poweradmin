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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;

#[CoversClass(DbUserGroupRepository::class)]
class DbUserGroupRepositoryTest extends TestCase
{
    private PDO&MockObject $db;
    private DbUserGroupRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMock(PDO::class);
        $this->repo = new DbUserGroupRepository($this->db);
    }

    #[Test]
    public function findExistingIdsReturnsEmptyForEmptyInputWithoutQuerying(): void
    {
        $this->db->expects($this->never())->method('prepare');

        $this->assertSame([], $this->repo->findExistingIds([]));
    }

    #[Test]
    public function findExistingIdsBindsOnePlaceholderPerId(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([3, 5, 8])
            ->willReturn(true);
        $stmt->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn(['3', '8']);

        $this->db->expects($this->once())
            ->method('prepare')
            ->with($this->matchesRegularExpression('/SELECT id FROM user_groups WHERE id IN \(\?,\?,\?\)/'))
            ->willReturn($stmt);

        $this->assertSame([3, 8], $this->repo->findExistingIds([3, 5, 8]));
    }

    #[Test]
    public function findExistingIdsHandlesSingleId(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn(['7']);

        $this->db->expects($this->once())
            ->method('prepare')
            ->with($this->matchesRegularExpression('/IN \(\?\)/'))
            ->willReturn($stmt);

        $this->assertSame([7], $this->repo->findExistingIds([7]));
    }

    #[Test]
    public function findExistingIdsCoercesAllRowsToInt(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn(['1', '2', '3']);

        $this->db->method('prepare')->willReturn($stmt);

        $result = $this->repo->findExistingIds([1, 2, 3]);
        $this->assertSame([1, 2, 3], $result);
        foreach ($result as $value) {
            $this->assertIsInt($value);
        }
    }

    #[Test]
    public function findExistingIdsReturnsEmptyArrayWhenNoneExist(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);

        $this->db->method('prepare')->willReturn($stmt);

        $this->assertSame([], $this->repo->findExistingIds([1, 2]));
    }
}
