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
 * Deleting a zone also deletes its PowerDNS metadata and DNSSEC keys, so a zone created later
 * under a reused domain id does not inherit them.
 */
class DeleteDomainLeftoversTest extends TestCase
{
    private const USER_ID = 100;
    private const GONE = 3;
    private const STAYS = 4;

    private PDOLayer $db;

    protected function setUp(): void
    {
        $this->db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)",
                "CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL)",
                "CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER NOT NULL, perm_id INTEGER NOT NULL)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, perm_templ INTEGER NOT NULL)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)",
                "CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)",
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)",
                "CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)",
                "CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, flags INTEGER, active INTEGER, content TEXT)",
                "CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "INSERT INTO perm_items (id, name) VALUES (59, 'zone_content_edit_own')",
                "INSERT INTO perm_templ (id, name) VALUES (100, 'Owner')",
                "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (100, 59)",
                "INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ID . ", 'owner', 100)",
                "INSERT INTO zones (domain_id, owner) VALUES (" . self::GONE . ", " . self::USER_ID . "), (" . self::STAYS . ", " . self::USER_ID . ")",
                "INSERT INTO domains (id, name, type) VALUES (" . self::GONE . ", 'gone.example', 'MASTER'), (" . self::STAYS . ", 'stays.example', 'MASTER')",
                "INSERT INTO domainmetadata (domain_id, kind, content) VALUES (" . self::GONE . ", 'ALLOW-AXFR-FROM', '0.0.0.0/0'), (" . self::STAYS . ", 'ALSO-NOTIFY', '192.0.2.1')",
                "INSERT INTO cryptokeys (domain_id, flags, active, content) VALUES (" . self::GONE . ", 257, 1, 'key'), (" . self::STAYS . ", 257, 1, 'key')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $_SESSION['userid'] = self::USER_ID;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    #[RunInSeparateProcess]
    public function testDeleteDomainRemovesMetadataAndKeys(): void
    {
        $this->assertTrue($this->dnsRecord()->delete_domain(self::GONE));

        $this->assertOnlyTheOtherZoneKeepsRows();
    }

    #[RunInSeparateProcess]
    public function testDeleteDomainsRemovesMetadataAndKeys(): void
    {
        ob_start();
        try {
            $this->assertTrue($this->dnsRecord()->delete_domains([self::GONE]));
        } finally {
            ob_end_clean();
        }

        $this->assertOnlyTheOtherZoneKeepsRows();
    }

    private function dnsRecord(): DnsRecord
    {
        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturn('');

        return new DnsRecord($this->db, $config);
    }

    private function assertOnlyTheOtherZoneKeepsRows(): void
    {
        foreach (['domainmetadata', 'cryptokeys', 'domains'] as $table) {
            $column = $table === 'domains' ? 'id' : 'domain_id';
            $rows = $this->db->query("SELECT $column FROM $table")->fetchAll(PDO::FETCH_COLUMN);
            $this->assertSame([self::STAYS], array_map('intval', $rows), $table);
        }
    }
}
