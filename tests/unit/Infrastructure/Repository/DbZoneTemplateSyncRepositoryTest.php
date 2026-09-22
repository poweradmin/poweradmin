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
use Poweradmin\Infrastructure\Repository\DbZoneTemplateSyncRepository;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Issue #1249: zone_template_sync keeps stale entries when a zone changes
 * template, and the UPDATE-then-INSERT upsert must not create a duplicate.
 *
 * @see https://github.com/poweradmin/poweradmin/issues/1249
 */
#[CoversClass(DbZoneTemplateSyncRepository::class)]
class DbZoneTemplateSyncRepositoryTest extends SqliteIntegrationTestCase
{
    private const ZONE = 34;

    private DbZoneTemplateSyncRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        // The production schema's unique key, so a duplicate insert fails loudly
        $this->db->exec("CREATE UNIQUE INDEX idx_zone_template_unique ON zone_template_sync (zone_id, zone_templ_id)");

        $this->repository = new DbZoneTemplateSyncRepository($this->db, $this->config);
    }

    /** @return list<array{zone_templ_id: int, needs_sync: int}> */
    private function syncRows(int $zoneId = self::ZONE): array
    {
        $stmt = $this->db->prepare("SELECT zone_templ_id, needs_sync FROM zone_template_sync WHERE zone_id = ? ORDER BY zone_templ_id");
        $stmt->execute([$zoneId]);

        return array_map(
            fn(array $row): array => [
                'zone_templ_id' => (int)$row['zone_templ_id'],
                'needs_sync' => (int)$row['needs_sync'],
            ],
            $stmt->fetchAll()
        );
    }

    #[Test]
    public function createSyncRecordAddsTheRowAndMarksItForSync(): void
    {
        $this->repository->createSyncRecord(self::ZONE, 2);

        $this->assertSame([['zone_templ_id' => 2, 'needs_sync' => 1]], $this->syncRows());
    }

    #[Test]
    public function createSyncRecordIsIdempotentForTheSamePair(): void
    {
        $this->repository->createSyncRecord(self::ZONE, 2);
        $this->repository->markZoneAsSynced(self::ZONE, 2);
        $this->repository->createSyncRecord(self::ZONE, 2);

        $this->assertSame([['zone_templ_id' => 2, 'needs_sync' => 1]], $this->syncRows());
    }

    #[Test]
    public function removeStaleSyncRecordsKeepsOnlyTheCurrentTemplate(): void
    {
        $this->repository->createSyncRecord(self::ZONE, 1);
        $this->repository->createSyncRecord(self::ZONE, 2);
        $this->repository->createSyncRecord(99, 1);

        $this->repository->removeStaleSyncRecords(self::ZONE, 2);

        $this->assertSame([['zone_templ_id' => 2, 'needs_sync' => 1]], $this->syncRows());
        $this->assertCount(1, $this->syncRows(99));
    }

    #[Test]
    public function removeStaleSyncRecordsClearsEveryRowWhenKeepIsZero(): void
    {
        $this->repository->createSyncRecord(self::ZONE, 1);
        $this->repository->createSyncRecord(self::ZONE, 2);

        $this->repository->removeStaleSyncRecords(self::ZONE, 0);

        $this->assertSame([], $this->syncRows());
    }

    #[Test]
    public function markZoneAsSyncedClearsTheFlagAndCreatesAMissingRow(): void
    {
        $this->repository->markZoneAsSynced(self::ZONE, 4);

        $this->assertSame([['zone_templ_id' => 4, 'needs_sync' => 0]], $this->syncRows());
    }

    #[Test]
    public function markTemplateAsModifiedFlagsEveryZoneUsingIt(): void
    {
        $this->repository->markZoneAsSynced(self::ZONE, 4);
        $this->repository->markZoneAsSynced(99, 4);
        $this->repository->markZoneAsSynced(100, 5);

        $this->repository->markTemplateAsModified(4);

        $this->assertSame([['zone_templ_id' => 4, 'needs_sync' => 1]], $this->syncRows());
        $this->assertSame([['zone_templ_id' => 4, 'needs_sync' => 1]], $this->syncRows(99));
        $this->assertSame([['zone_templ_id' => 5, 'needs_sync' => 0]], $this->syncRows(100));
    }

    /**
     * MySQL reports zero affected rows for an UPDATE that matches a row without
     * changing a value; SQLite counts the match, so only a mocked PDO can put
     * the upsert in that state.
     */
    #[Test]
    public function createSyncRecordDoesNotInsertWhenTheUpdateAffectsNoRowsButTheRowExists(): void
    {
        $updateStatement = $this->createMock(PDOStatement::class);
        $updateStatement->method('execute')->willReturn(true);
        $updateStatement->method('rowCount')->willReturn(0);

        $existsStatement = $this->createMock(PDOStatement::class);
        $existsStatement->expects($this->once())->method('execute');
        $existsStatement->method('fetchColumn')->willReturn(1);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($updateStatement, $existsStatement): PDOStatement {
                if (str_contains($sql, 'INSERT INTO zone_template_sync')) {
                    $this->fail('createSyncRecord must not INSERT when a sync row already exists');
                }
                return str_contains($sql, 'SELECT 1 FROM zone_template_sync') ? $existsStatement : $updateStatement;
            }
        );

        (new DbZoneTemplateSyncRepository($db, $this->sqliteConfiguration()))->createSyncRecord(7, 11);
    }
}
