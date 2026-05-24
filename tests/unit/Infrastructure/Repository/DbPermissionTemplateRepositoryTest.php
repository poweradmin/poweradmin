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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use RuntimeException;

#[CoversClass(DbPermissionTemplateRepository::class)]
class DbPermissionTemplateRepositoryTest extends TestCase
{
    private DbPermissionTemplateRepository $repository;
    private PDO&MockObject $db;
    private ConfigurationManager&MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(PDO::class);
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->repository = new DbPermissionTemplateRepository($this->db, $this->config);
    }

    #[Test]
    public function testAddPermissionTemplatePassesPostgresSequenceNameToLastInsertId(): void
    {
        $templStmt = $this->createMock(PDOStatement::class);
        $templStmt->expects($this->once())->method('execute')->willReturn(true);

        $this->db->method('prepare')->willReturn($templStmt);
        $this->db->method('beginTransaction')->willReturn(true);
        $this->db->method('commit')->willReturn(true);

        $this->db->expects($this->once())
            ->method('lastInsertId')
            ->with('perm_templ_id_seq')
            ->willReturn('42');

        $result = $this->repository->addPermissionTemplate([
            'templ_name' => 'Test Template',
            'templ_descr' => 'Description',
            'template_type' => 'user',
        ]);

        $this->assertTrue($result);
    }

    #[Test]
    public function testAddPermissionTemplateWrapsInsertsInTransactionAndCommits(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $this->db->method('prepare')->willReturn($stmt);
        $this->db->method('lastInsertId')->willReturn('7');

        $this->db->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->db->expects($this->once())->method('commit')->willReturn(true);
        $this->db->expects($this->never())->method('rollBack');

        $result = $this->repository->addPermissionTemplate([
            'templ_name' => 'With Perms',
            'templ_descr' => 'Description',
            'template_type' => 'user',
            'perm_id' => [1, 2, 3],
        ]);

        $this->assertTrue($result);
    }

    #[Test]
    public function testAddPermissionTemplateRollsBackWhenItemInsertFails(): void
    {
        $templStmt = $this->createMock(PDOStatement::class);
        $templStmt->method('execute')->willReturn(true);

        $itemStmt = $this->createMock(PDOStatement::class);
        $itemStmt->method('execute')->willThrowException(new RuntimeException('item insert failed'));

        $this->db->method('prepare')->willReturnOnConsecutiveCalls($templStmt, $itemStmt);
        $this->db->method('lastInsertId')->willReturn('9');

        $this->db->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->db->expects($this->never())->method('commit');
        $this->db->expects($this->once())->method('rollBack')->willReturn(true);

        $this->expectException(RuntimeException::class);

        $this->repository->addPermissionTemplate([
            'templ_name' => 'Broken',
            'templ_descr' => 'Description',
            'template_type' => 'user',
            'perm_id' => [1],
        ]);
    }

    #[Test]
    public function testAddPermissionTemplateSkipsItemInsertWhenNoPermIds(): void
    {
        $templStmt = $this->createMock(PDOStatement::class);
        $templStmt->expects($this->once())->method('execute')->willReturn(true);

        $this->db->expects($this->once())->method('prepare')->willReturn($templStmt);
        $this->db->method('beginTransaction')->willReturn(true);
        $this->db->method('commit')->willReturn(true);
        $this->db->method('lastInsertId')->willReturn('11');

        $result = $this->repository->addPermissionTemplate([
            'templ_name' => 'Empty',
            'templ_descr' => 'No perms',
        ]);

        $this->assertTrue($result);
    }
}
