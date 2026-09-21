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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\ValueObject\RecordIdentifier;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use TestHelpers\FakeConfiguration;

/**
 * unlinkRecord() and unlinkZone() drop template links by record and by zone.
 *
 * Issue #1206: PostgreSQL rejects API-mode encoded record IDs (base64 strings)
 * being bound to the integer records_zone_templ.record_id column, breaking
 * record deletion when running with the PowerDNS REST API backend, so an
 * encoded id must only ever reach records_zone_templ_api.
 *
 * @see https://github.com/poweradmin/poweradmin/issues/1206
 */
#[CoversClass(DbTemplateRecordLinkRepository::class)]
class DbTemplateRecordLinkRepositoryUnlinkRecordTest extends TestCase
{
    private PDO $db;
    private string $encodedId;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE records_zone_templ_api (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id TEXT, zone_templ_id INTEGER)");
        $this->encodedId = RecordIdentifier::encode('admin-zone.example.com', 'www.admin-zone.example.com', 'A', '192.0.2.1', 0);

        $this->db->exec("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (10, 42, 7), (10, 43, 7), (11, 44, 7)");
        $stmt = $this->db->prepare("INSERT INTO records_zone_templ_api (domain_id, record_id, zone_templ_id) VALUES (10, ?, 7), (11, 'other', 7)");
        $stmt->execute([$this->encodedId]);
    }

    public function testIntegerIdIsRemovedFromTheSqlTableOnly(): void
    {
        $this->repository()->unlinkRecord(42);

        $this->assertSame([43, 44], $this->sqlLinks());
        $this->assertSame([$this->encodedId, 'other'], $this->apiLinks());
    }

    public function testNumericStringIdIsRemovedFromTheSqlTableOnly(): void
    {
        $this->repository()->unlinkRecord('42');

        $this->assertSame([43, 44], $this->sqlLinks());
        $this->assertSame([$this->encodedId, 'other'], $this->apiLinks());
    }

    public function testEncodedApiIdIsRemovedFromTheApiTableOnly(): void
    {
        $this->repository()->unlinkRecord($this->encodedId);

        $this->assertSame([42, 43, 44], $this->sqlLinks());
        $this->assertSame(['other'], $this->apiLinks());
    }

    public function testUnlinkZoneClearsBothTablesForThatZoneOnly(): void
    {
        $this->repository()->unlinkZone(10);

        $this->assertSame([44], $this->sqlLinks());
        $this->assertSame(['other'], $this->apiLinks());
    }

    private function repository(): DbTemplateRecordLinkRepository
    {
        $backend = $this->createMock(BackendCapabilitiesInterface::class);
        $backend->method('recordIdsAreNumeric')->willReturn(true);

        return new DbTemplateRecordLinkRepository(
            $this->db,
            new FakeConfiguration(['database' => ['type' => 'sqlite', 'pdns_db_name' => '']]),
            $backend
        );
    }

    /**
     * @return int[]
     */
    private function sqlLinks(): array
    {
        return array_map('intval', $this->db->query('SELECT record_id FROM records_zone_templ ORDER BY record_id')->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return string[]
     */
    private function apiLinks(): array
    {
        return $this->db->query('SELECT record_id FROM records_zone_templ_api ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
}
