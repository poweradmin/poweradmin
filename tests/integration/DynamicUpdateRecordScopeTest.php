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

namespace integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * The dynamic DNS endpoint resolves the record to update inside a zone the caller
 * owns and then writes by record id. Two zones may legitimately hold records with
 * the same name, so a write keyed by name would reach the other tenant's zone.
 */
class DynamicUpdateRecordScopeTest extends TestCase
{
    private const ATTACKER_RECORD_ID = 10;
    private const VICTIM_RECORD_ID = 20;

    private PDOLayer $db;
    private DnsRecord $dnsRecord;

    protected function setUp(): void
    {
        $this->db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");

        // Same record name in two different zones
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (" . self::ATTACKER_RECORD_ID . ", 1, 'www.example.com', 'A', '203.0.113.1', 60, 0)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (" . self::VICTIM_RECORD_ID . ", 2, 'www.example.com', 'A', '198.51.100.9', 60, 0)");

        $this->dnsRecord = new DnsRecord($this->db, $this->createMock(AppConfiguration::class));
    }

    public function testUpdateTouchesOnlyTheMatchedRow(): void
    {
        $this->assertTrue($this->dnsRecord->update_dynamic_record_content(self::ATTACKER_RECORD_ID, '192.0.2.66'));

        $this->assertSame('192.0.2.66', $this->contentOf(self::ATTACKER_RECORD_ID));
        $this->assertSame('198.51.100.9', $this->contentOf(self::VICTIM_RECORD_ID), 'The same-named record in the other zone must be left untouched.');
    }

    public function testUnknownRecordIdUpdatesNothing(): void
    {
        $this->assertFalse($this->dnsRecord->update_dynamic_record_content(999, '192.0.2.66'));

        $this->assertSame('203.0.113.1', $this->contentOf(self::ATTACKER_RECORD_ID));
        $this->assertSame('198.51.100.9', $this->contentOf(self::VICTIM_RECORD_ID));
    }

    private function contentOf(int $recordId): string
    {
        return (string)$this->db->queryOne("SELECT content FROM records WHERE id = " . $recordId);
    }
}
