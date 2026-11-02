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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DatabaseConsistencyService;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * The orphaned-record and duplicate-SOA repairs act on the stored rows, not on the submitted id alone.
 */
#[CoversClass(DatabaseConsistencyService::class)]
class DatabaseConsistencyRepairGateTest extends TestCase
{
    private PDO $db;
    private ConfigurationManager $config;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)");
        $config = $this->createStub(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, mixed $default = null) => $default);
        $this->config = $config;
    }

    private function service(?DnsBackendProvider $backend = null): DatabaseConsistencyService
    {
        return new DatabaseConsistencyService($this->db, $this->config, $backend);
    }

    public function testAnOrphanedRecordIsDeleted(): void
    {
        $this->db->exec("INSERT INTO records (id, domain_id, name, type) VALUES (1, 99, 'lost.example.org', 'A')");

        $this->assertTrue($this->service()->deleteOrphanedRecord(1));
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM records')->fetchColumn());
    }

    public function testARecordOfALiveZoneIsNotDeletedAsAnOrphan(): void
    {
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'example.com', 'MASTER')");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type) VALUES (1, 1, 'www.example.com', 'A')");

        $this->assertFalse($this->service()->deleteOrphanedRecord(1));
        $this->assertFalse($this->service()->deleteOrphanedRecord(404));
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM records')->fetchColumn());
    }

    public function testTheApiBackendNeverDeletesARecordAsAnOrphan(): void
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->expects($this->never())->method('deleteRecord');

        $this->assertFalse($this->service($backend)->deleteOrphanedRecord(77));
    }

    public function testTheDuplicateSoaRepairKeepsTheApexSoaOverALowerIdStray(): void
    {
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'example.com', 'MASTER')");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content) VALUES
            (3, 1, 'stray.example.com', 'SOA', 'stray'), (5, 1, 'Example.com', 'SOA', 'apex'), (8, 1, 'example.com', 'SOA', 'copy')");

        $this->assertTrue($this->service()->fixDuplicateSOA(1));
        $this->assertSame([5], array_map('intval', $this->db->query("SELECT id FROM records")->fetchAll(PDO::FETCH_COLUMN)));
    }
}
