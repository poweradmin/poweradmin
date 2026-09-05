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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Bulk zone deletion refuses zones the caller may not delete and must report that
 * refusal. A result of true for a fully refused set previously let the controller
 * run its follow-up cleanup for every posted zone id.
 */
class DeleteDomainsPermissionTest extends TestCase
{
    private const USER_ID = 100;
    private const PERM_TEMPL_ID = 100;
    private const OWN_ZONE_ID = 3;
    private const FOREIGN_ZONE_ID = 2;

    private PDOLayer $db;

    protected function setUp(): void
    {
        $this->db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER NOT NULL, perm_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, perm_templ INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)");
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (59, 'zone_content_edit_own')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::PERM_TEMPL_ID . ", 'Owner')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::PERM_TEMPL_ID . ", 59)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ID . ", 'owner', " . self::PERM_TEMPL_ID . ")");

        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, " . self::FOREIGN_ZONE_ID . ", 1)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (2, " . self::OWN_ZONE_ID . ", " . self::USER_ID . ")");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (" . self::FOREIGN_ZONE_ID . ", 'foreign.example', 'MASTER')");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (" . self::OWN_ZONE_ID . ", 'own.example', 'MASTER')");

        $_SESSION['userid'] = self::USER_ID;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    #[RunInSeparateProcess]
    public function testRefusedSetIsReportedAsFailure(): void
    {
        $this->assertFalse($this->deleteDomains([self::FOREIGN_ZONE_ID]));

        $this->assertSame(1, $this->domainRows(self::FOREIGN_ZONE_ID), 'The foreign zone must survive.');
    }

    #[RunInSeparateProcess]
    public function testOwnZoneIsDeletedAndReportedAsSuccess(): void
    {
        $this->assertTrue($this->deleteDomains([self::OWN_ZONE_ID]));

        $this->assertSame(0, $this->domainRows(self::OWN_ZONE_ID));
        $this->assertSame(1, $this->domainRows(self::FOREIGN_ZONE_ID));
    }

    #[RunInSeparateProcess]
    public function testMixedSetDeletesOnlyTheOwnedZone(): void
    {
        $this->assertTrue($this->deleteDomains([self::FOREIGN_ZONE_ID, self::OWN_ZONE_ID]));

        $this->assertSame(0, $this->domainRows(self::OWN_ZONE_ID));
        $this->assertSame(1, $this->domainRows(self::FOREIGN_ZONE_ID));
    }

    private function deleteDomains(array $ids): bool
    {
        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturn('');
        $dnsRecord = new DnsRecord($this->db, $config);

        ob_start();
        try {
            return $dnsRecord->delete_domains($ids);
        } finally {
            ob_end_clean();
        }
    }

    private function domainRows(int $domainId): int
    {
        return (int)$this->db->queryOne("SELECT COUNT(*) FROM domains WHERE id = " . $domainId);
    }
}
