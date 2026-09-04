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

namespace Unit\Domain\Service\Dns;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\ValueObject\RecordIdentifier;

/**
 * Test for RecordManager::deleteRecordZoneTempl().
 *
 * Issue #1206: PostgreSQL rejects API-mode encoded record IDs (base64 strings)
 * being bound to the integer records_zone_templ.record_id column, breaking
 * record deletion when running with the PowerDNS REST API backend.
 *
 * @see https://github.com/poweradmin/poweradmin/issues/1206
 */
class RecordManagerDeleteRecordZoneTemplTest extends TestCase
{
    public function testDeleteWithIntegerIdTouchesOnlySqlTable(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([42])
            ->willReturn(true);

        $db = $this->createMock(PDO::class);
        $db->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE FROM records_zone_templ '))
            ->willReturn($stmt);

        $this->assertTrue(RecordManager::deleteRecordZoneTempl($db, 42));
    }

    public function testDeleteWithNumericStringIdTouchesOnlySqlTable(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([42])
            ->willReturn(true);

        $db = $this->createMock(PDO::class);
        $db->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE FROM records_zone_templ '))
            ->willReturn($stmt);

        $this->assertTrue(RecordManager::deleteRecordZoneTempl($db, '42'));
    }

    /**
     * In API mode the record id is a base64-encoded composite identifier
     * (RecordIdentifier::encode). It must never be sent to the integer
     * records_zone_templ.record_id column - PostgreSQL would raise
     * SQLSTATE[22P02] and abort the whole delete request. Encoded ids only
     * belong in records_zone_templ_api.
     */
    public function testDeleteWithEncodedApiIdTouchesOnlyApiTable(): void
    {
        $encodedId = RecordIdentifier::encode(
            'admin-zone.example.com',
            'www.admin-zone.example.com',
            'A',
            '192.0.2.1',
            0
        );

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([$encodedId])
            ->willReturn(true);

        $db = $this->createMock(PDO::class);
        $db->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE FROM records_zone_templ_api'))
            ->willReturn($stmt);

        $this->assertTrue(RecordManager::deleteRecordZoneTempl($db, $encodedId));
    }
}
