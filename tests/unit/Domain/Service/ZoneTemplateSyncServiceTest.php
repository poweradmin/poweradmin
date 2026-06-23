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

namespace Unit\Domain\Service;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ZoneTemplateSyncService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Test for ZoneTemplateSyncService::removeStaleSyncRecords()
 *
 * Issue #1249: zone_template_sync keeps stale entries when changing a zone template
 * @see https://github.com/poweradmin/poweradmin/issues/1249
 */
class ZoneTemplateSyncServiceTest extends TestCase
{
    public function testRemoveStaleSyncRecordsDeletesRowsForOtherTemplates(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())
            ->method('execute')
            ->with($this->equalTo([
                'zone_id' => 34,
                'keep_id' => 2,
            ]));

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->callback(function (string $sql): bool {
                return str_contains($sql, 'DELETE FROM zone_template_sync')
                    && str_contains($sql, 'zone_id = :zone_id')
                    && str_contains($sql, 'zone_templ_id <> :keep_id');
            }))
            ->willReturn($statement);

        $config = $this->createMock(ConfigurationInterface::class);

        $service = new ZoneTemplateSyncService($pdo, $config);
        $service->removeStaleSyncRecords(34, 2);
    }

    public function testRemoveStaleSyncRecordsClearsAllWhenKeepIsZero(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())
            ->method('execute')
            ->with($this->equalTo([
                'zone_id' => 34,
                'keep_id' => 0,
            ]));

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $config = $this->createMock(ConfigurationInterface::class);

        $service = new ZoneTemplateSyncService($pdo, $config);
        $service->removeStaleSyncRecords(34, 0);
    }

    /**
     * Regression: createSyncRecord must not INSERT when the sync row already
     * exists but the UPDATE reports zero affected rows. MySQL returns zero for
     * an UPDATE that matches a row without changing any value, which previously
     * triggered a duplicate INSERT and a unique-key violation on
     * idx_zone_template_unique (the "update zones from template" fatal error).
     */
    public function testCreateSyncRecordDoesNotInsertWhenRowExistsButUpdateAffectsNoRows(): void
    {
        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);
        $updateStmt->method('rowCount')->willReturn(0);

        $existsStmt = $this->createMock(PDOStatement::class);
        $existsStmt->expects($this->once())->method('execute');
        $existsStmt->method('fetchColumn')->willReturn(1);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($updateStmt, $existsStmt) {
            if (str_contains($sql, 'INSERT INTO zone_template_sync')) {
                $this->fail('createSyncRecord must not INSERT when a sync row already exists');
            }
            return str_contains($sql, 'SELECT 1 FROM zone_template_sync') ? $existsStmt : $updateStmt;
        });

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturn('mysql');

        $service = new ZoneTemplateSyncService($pdo, $config);
        $service->createSyncRecord(7, 11);
    }

    /**
     * The existence guard must not suppress a legitimate insert: when no sync
     * row exists and the UPDATE affects nothing, the row is still created.
     */
    public function testCreateSyncRecordInsertsWhenRowDoesNotExist(): void
    {
        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);
        $updateStmt->method('rowCount')->willReturn(0);

        $existsStmt = $this->createMock(PDOStatement::class);
        $existsStmt->method('execute');
        $existsStmt->method('fetchColumn')->willReturn(false);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->expects($this->once())->method('execute');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($updateStmt, $existsStmt, $insertStmt) {
            if (str_contains($sql, 'INSERT INTO zone_template_sync')) {
                return $insertStmt;
            }
            return str_contains($sql, 'SELECT 1 FROM zone_template_sync') ? $existsStmt : $updateStmt;
        });

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturn('mysql');

        $service = new ZoneTemplateSyncService($pdo, $config);
        $service->createSyncRecord(7, 11);
    }
}
