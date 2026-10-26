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

namespace Poweradmin\Tests\Unit\Dns;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * The SQL serial bump: a locked read and a write by primary key, in the caller's
 * transaction or its own, applied to every SOA row of the zone.
 */
class SOARecordManagerSqlSerialTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER)');
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES
            (1, 1, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 1000 28800 7200 604800 86400', 86400, 0),
            (2, 1, 'www.example.com', 'A', '192.0.2.1', 300, 0),
            (3, 2, 'other.org', 'SOA', 'ns1.other.org hostmaster.other.org 1000 28800 7200 604800 86400', 86400, 0)");
    }

    private function manager(): SOARecordManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn(null);

        return new SOARecordManager($this->db, $config);
    }

    /** @return list<string> */
    private function soaContents(int $zoneId): array
    {
        return $this->db->query("SELECT content FROM records WHERE domain_id = $zoneId AND type = 'SOA' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testTheBumpCommitsItsOwnTransaction(): void
    {
        $this->assertTrue($this->manager()->updateSOASerial(1));

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(['ns1.example.com hostmaster.example.com 1001 28800 7200 604800 86400'], $this->soaContents(1));
        $this->assertSame(['ns1.other.org hostmaster.other.org 1000 28800 7200 604800 86400'], $this->soaContents(2));
    }

    public function testTheBumpJoinsAnOpenTransaction(): void
    {
        $this->db->beginTransaction();

        $this->manager()->updateSOASerial(1);
        $this->assertTrue($this->db->inTransaction(), 'The caller still owns the transaction');
        $this->db->rollBack();

        $this->assertSame(['ns1.example.com hostmaster.example.com 1000 28800 7200 604800 86400'], $this->soaContents(1));
    }

    public function testEverySoaRowOfTheZoneGetsTheBump(): void
    {
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (4, 1, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 1000 28800 7200 604800 86400', 86400, 0)");

        $this->manager()->updateSOASerial(1);

        $this->assertSame(array_fill(0, 2, 'ns1.example.com hostmaster.example.com 1001 28800 7200 604800 86400'), $this->soaContents(1));
    }

    public function testAZoneWithoutASoaRecordFails(): void
    {
        $this->assertFalse($this->manager()->updateSOASerial(9));
    }

    public function testUpdateSoaRecordRewritesOnlyTheSoaRows(): void
    {
        $this->assertTrue($this->manager()->updateSOARecord(1, 'ns2.example.com admin.example.com 5 1 1 1 1'));

        $this->assertSame(['ns2.example.com admin.example.com 5 1 1 1 1'], $this->soaContents(1));
        $this->assertSame('192.0.2.1', $this->db->query('SELECT content FROM records WHERE id = 2')->fetchColumn());
    }
}
