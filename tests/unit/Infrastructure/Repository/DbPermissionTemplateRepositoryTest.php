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
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use RuntimeException;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Permission templates and their permission lists are written in one
 * transaction: a failed item insert must leave the template behind, and an
 * update without a perm_id key must leave the existing permissions alone.
 */
#[CoversClass(DbPermissionTemplateRepository::class)]
class DbPermissionTemplateRepositoryTest extends SqliteIntegrationTestCase
{
    private DbPermissionTemplateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DbPermissionTemplateRepository($this->db, $this->config);
    }

    /** @return array{name: string, descr: string, template_type: string} */
    private function templateRow(string $name): array
    {
        $stmt = $this->db->prepare("SELECT name, descr, template_type FROM perm_templ WHERE name = ?");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "no perm_templ row named $name");

        return ['name' => $row['name'], 'descr' => $row['descr'], 'template_type' => $row['template_type']];
    }

    /** @return list<int> */
    private function permissionIds(int $templateId): array
    {
        $stmt = $this->db->prepare("SELECT perm_id FROM perm_templ_items WHERE templ_id = ? ORDER BY perm_id");
        $stmt->execute([$templateId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function newTemplateId(): int
    {
        return (int)$this->db->query("SELECT MAX(id) FROM perm_templ")->fetchColumn();
    }

    #[Test]
    public function addingATemplateStoresItsDetailsAndPermissions(): void
    {
        $this->assertTrue($this->repository->addPermissionTemplate([
            'templ_name' => 'With Perms',
            'templ_descr' => 'Description',
            'template_type' => 'group',
            'perm_id' => [47, 53],
        ]));

        $this->assertSame(
            ['name' => 'With Perms', 'descr' => 'Description', 'template_type' => 'group'],
            $this->templateRow('With Perms')
        );
        $this->assertSame([47, 53], $this->permissionIds($this->newTemplateId()));
    }

    #[Test]
    public function addingATemplateWithoutPermIdsLeavesItEmptyAndDefaultsToAUserTemplate(): void
    {
        $this->assertTrue($this->repository->addPermissionTemplate([
            'templ_name' => 'Empty',
            'templ_descr' => 'No perms',
        ]));

        $this->assertSame('user', $this->templateRow('Empty')['template_type']);
        $this->assertSame([], $this->permissionIds($this->newTemplateId()));
    }

    #[Test]
    public function aFailedItemInsertRollsBackTheWholeTemplate(): void
    {
        // perm_templ_items.perm_id has no default, so a NULL id aborts the insert
        $this->db->exec("CREATE TRIGGER reject_perm AFTER INSERT ON perm_templ_items
            BEGIN SELECT RAISE(ABORT, 'item insert failed'); END");

        try {
            $this->repository->addPermissionTemplate([
                'templ_name' => 'Broken',
                'templ_descr' => 'Description',
                'template_type' => 'user',
                'perm_id' => [47],
            ]);
            $this->fail('the item insert was expected to abort');
        } catch (\PDOException) {
            // the rollback is what this test is about
        }

        $this->assertSame(
            '0',
            (string)$this->db->query("SELECT COUNT(*) FROM perm_templ WHERE name = 'Broken'")->fetchColumn()
        );
    }

    #[Test]
    public function updatingWithoutAPermIdKeyPreservesTheExistingPermissions(): void
    {
        $this->assertTrue($this->repository->updatePermissionTemplateDetails([
            'templ_id' => self::ADMIN_PERM_TEMPL_ID,
            'templ_name' => 'Renamed',
            'templ_descr' => 'Only a rename',
            'template_type' => 'user',
        ]));

        $this->assertSame('Renamed', $this->templateRow('Renamed')['name']);
        $this->assertSame([47, 53], $this->permissionIds(self::ADMIN_PERM_TEMPL_ID));
    }

    #[Test]
    public function updatingWithAnEmptyPermIdListClearsThePermissions(): void
    {
        $this->assertTrue($this->repository->updatePermissionTemplateDetails([
            'templ_id' => self::ADMIN_PERM_TEMPL_ID,
            'templ_name' => 'Cleared',
            'templ_descr' => 'All perms removed',
            'template_type' => 'user',
            'perm_id' => [],
        ]));

        $this->assertSame([], $this->permissionIds(self::ADMIN_PERM_TEMPL_ID));
    }

    #[Test]
    public function updatingWithAPermIdListReplacesThePermissions(): void
    {
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'zone_master_add'), (2, 'zone_slave_add')");

        $this->assertTrue($this->repository->updatePermissionTemplateDetails([
            'templ_id' => self::ADMIN_PERM_TEMPL_ID,
            'templ_name' => 'Replaced',
            'templ_descr' => 'New perms',
            'template_type' => 'user',
            'perm_id' => [1, 2],
        ]));

        $this->assertSame([1, 2], $this->permissionIds(self::ADMIN_PERM_TEMPL_ID));
    }

    #[Test]
    public function aFailedUpdateRollsBackTheRename(): void
    {
        $this->db->exec("CREATE TRIGGER reject_item_delete AFTER DELETE ON perm_templ_items
            BEGIN SELECT RAISE(ABORT, 'delete failed'); END");

        try {
            $this->repository->updatePermissionTemplateDetails([
                'templ_id' => self::ADMIN_PERM_TEMPL_ID,
                'templ_name' => 'Broken',
                'templ_descr' => 'fails',
                'template_type' => 'user',
                'perm_id' => [47],
            ]);
            $this->fail('the item delete was expected to abort');
        } catch (\PDOException) {
            // the rollback is what this test is about
        }

        $this->assertSame('Administrator', $this->templateRow('Administrator')['name']);
        $this->assertSame([47, 53], $this->permissionIds(self::ADMIN_PERM_TEMPL_ID));
    }

    /**
     * PostgreSQL needs the sequence name to resolve the new id; MySQL and SQLite
     * ignore the argument, so only a mocked PDO can observe that it is passed.
     */
    #[Test]
    public function theNewTemplateIdIsReadFromThePostgresSequence(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($statement);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('commit')->willReturn(true);
        $db->expects($this->once())
            ->method('lastInsertId')
            ->with('perm_templ_id_seq')
            ->willReturn('42');

        $repository = new DbPermissionTemplateRepository($db, $this->sqliteConfiguration());

        $this->assertTrue($repository->addPermissionTemplate([
            'templ_name' => 'Test Template',
            'templ_descr' => 'Description',
            'template_type' => 'user',
            'perm_id' => [47],
        ]));
    }

    #[Test]
    public function rollingBackRethrowsTheOriginalFailure(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willThrowException(new RuntimeException('update failed'));

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($statement);
        $db->expects($this->once())->method('beginTransaction')->willReturn(true);
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollBack')->willReturn(true);

        $this->expectException(RuntimeException::class);

        (new DbPermissionTemplateRepository($db, $this->sqliteConfiguration()))
            ->updatePermissionTemplateDetails([
                'templ_id' => 5,
                'templ_name' => 'Broken',
                'templ_descr' => 'fails',
                'template_type' => 'user',
                'perm_id' => [1],
            ]);
    }
}
