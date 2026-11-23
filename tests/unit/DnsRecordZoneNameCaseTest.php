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

namespace unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Zone names are matched case-insensitively, so a zone stored as Example.COM
 * is found when the new zone is requested as example.com.
 */
class DnsRecordZoneNameCaseTest extends TestCase
{
    private DnsRecord $dnsRecord;

    protected function setUp(): void
    {
        $db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)");
        $db->exec("INSERT INTO domains (id, name, type) VALUES (7, 'Example.COM', 'MASTER')");

        $this->dnsRecord = new DnsRecord($db, $this->createMock(AppConfiguration::class));
    }

    public function testDomainExistsIgnoresCase(): void
    {
        $this->assertTrue($this->dnsRecord->domain_exists('example.com'));
        $this->assertTrue($this->dnsRecord->domain_exists('EXAMPLE.com'));
    }

    public function testDomainExistsStillFalseForOtherName(): void
    {
        $this->assertFalse($this->dnsRecord->domain_exists('other.com'));
    }

    public function testDomainIdByNameIgnoresCase(): void
    {
        $this->assertSame(7, (int)$this->dnsRecord->get_domain_id_by_name('example.com'));
    }

    public function testExactLowercaseRowWinsWhenBothCasesExist(): void
    {
        $db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)");
        $db->exec("INSERT INTO domains (id, name, type) VALUES (3, 'Example.com', 'MASTER')");
        $db->exec("INSERT INTO domains (id, name, type) VALUES (9, 'example.com', 'MASTER')");
        $dnsRecord = new DnsRecord($db, $this->createMock(AppConfiguration::class));

        $this->assertSame(9, (int)$dnsRecord->get_domain_id_by_name('example.com'));
        $this->assertSame(9, (int)$dnsRecord->get_domain_id_by_name('EXAMPLE.COM'));
        $this->assertSame(9, (int)$dnsRecord->get_zone_id_from_name('example.com'));
    }

    public function testZoneIdFromNameIgnoresCase(): void
    {
        $this->assertSame(7, (int)$this->dnsRecord->get_zone_id_from_name('example.com'));
    }
}
