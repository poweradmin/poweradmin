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
 * A client-level editor may not add or edit NS and LUA records, so deleting them
 * must be refused as well; otherwise the delegation set of a zone could be stripped
 * one-way. Ordinary records stay deletable.
 */
class DnsRecordDeleteClientRestrictionTest extends TestCase
{
    private const CLIENT_USER_ID = 100;
    private const CLIENT_PERM_TEMPL_ID = 100;
    private const CLIENT_ZONE_ID = 3;
    private const A_RECORD_ID = 5;
    private const NS_RECORD_ID = 6;
    private const LUA_RECORD_ID = 7;

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

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (60, 'zone_content_edit_own_as_client')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::CLIENT_PERM_TEMPL_ID . ", 'Client')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::CLIENT_PERM_TEMPL_ID . ", 60)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::CLIENT_USER_ID . ", 'client', " . self::CLIENT_PERM_TEMPL_ID . ")");

        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, " . self::CLIENT_ZONE_ID . ", " . self::CLIENT_USER_ID . ")");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (" . self::CLIENT_ZONE_ID . ", 'client.example', 'MASTER')");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (" . self::A_RECORD_ID . ", " . self::CLIENT_ZONE_ID . ", 'www.client.example', 'A', '192.0.2.1', 3600, 0)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (" . self::NS_RECORD_ID . ", " . self::CLIENT_ZONE_ID . ", 'client.example', 'NS', 'ns1.example', 3600, 0)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (" . self::LUA_RECORD_ID . ", " . self::CLIENT_ZONE_ID . ", 'dyn.client.example', 'LUA', 'A \"192.0.2.2\"', 3600, 0)");

        $_SESSION['userid'] = self::CLIENT_USER_ID;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    #[RunInSeparateProcess]
    public function testClientLevelEditorCannotDeleteNsRecord(): void
    {
        $this->assertFalse($this->delete(self::NS_RECORD_ID));
        $this->assertSame(1, $this->rowsWithId(self::NS_RECORD_ID));
    }

    #[RunInSeparateProcess]
    public function testClientLevelEditorCannotDeleteLuaRecord(): void
    {
        $this->assertFalse($this->delete(self::LUA_RECORD_ID));
        $this->assertSame(1, $this->rowsWithId(self::LUA_RECORD_ID));
    }

    #[RunInSeparateProcess]
    public function testClientLevelEditorCanStillDeleteARecord(): void
    {
        $this->assertTrue($this->delete(self::A_RECORD_ID));
        $this->assertSame(0, $this->rowsWithId(self::A_RECORD_ID));
    }

    private function delete(int $recordId): bool
    {
        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturn('');
        $dnsRecord = new DnsRecord($this->db, $config);

        ob_start();
        try {
            return $dnsRecord->delete_record($recordId);
        } finally {
            ob_end_clean();
        }
    }

    private function rowsWithId(int $recordId): int
    {
        return (int)$this->db->queryOne("SELECT COUNT(*) FROM records WHERE id = " . $recordId);
    }
}
