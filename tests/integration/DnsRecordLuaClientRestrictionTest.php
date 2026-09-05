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
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;
use ReflectionMethod;

/**
 * LUA record content is evaluated inside the authoritative server. A client-level
 * editor (zone_content_edit_own_as_client) is held to the same rule as for SOA and
 * NS: no adding, no retyping an existing record into LUA, and no LUA template records.
 */
class DnsRecordLuaClientRestrictionTest extends TestCase
{
    private const CLIENT_USER_ID = 100;
    private const CLIENT_PERM_TEMPL_ID = 100;
    private const CLIENT_ZONE_ID = 3;
    private const A_RECORD_ID = 5;

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

        $_SESSION['userid'] = self::CLIENT_USER_ID;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    #[RunInSeparateProcess]
    public function testClientLevelEditorCannotAddLuaRecord(): void
    {
        $this->assertFalse($this->quietly(fn() => $this->dnsRecord()->add_record(self::CLIENT_ZONE_ID, 'lua', 'LUA', 'A "os.time()"', 3600, 0)));

        $this->assertSame(0, (int)$this->db->queryOne("SELECT COUNT(*) FROM records WHERE type = 'LUA'"));
    }

    #[RunInSeparateProcess]
    public function testClientLevelEditorCannotRetypeARecordToLua(): void
    {
        $record = [
            'rid' => self::A_RECORD_ID,
            'zid' => self::CLIENT_ZONE_ID,
            'name' => 'www',
            'type' => 'LUA',
            'content' => 'A "os.time()"',
            'prio' => 0,
            'ttl' => 3600,
            'disabled' => 0,
        ];

        $this->assertFalse($this->quietly(fn() => $this->dnsRecord()->edit_record($record)));

        $row = $this->db->queryRow("SELECT type, content FROM records WHERE id = " . self::A_RECORD_ID);
        $this->assertSame('A', $row['type']);
        $this->assertSame('192.0.2.1', $row['content']);
    }

    #[RunInSeparateProcess]
    public function testClientLevelEditorCannotStoreLuaTemplateRecords(): void
    {
        $method = new ReflectionMethod(ZoneTemplate::class, 'can_store_template_record_type');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, $this->db, 'LUA'));
        $this->assertFalse($method->invoke(null, $this->db, 'lua'));
        $this->assertTrue($method->invoke(null, $this->db, 'A'));
    }

    private function dnsRecord(): DnsRecord
    {
        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturnCallback(function (string $key) {
            return match ($key) {
                'pdns_db_name' => '',
                'dns_hostmaster' => 'hostmaster.example',
                'dns_ttl' => 3600,
                'dns_txt_auto_quote' => false,
                default => null,
            };
        });

        return new DnsRecord($this->db, $config);
    }

    /**
     * Run a call whose failure path prints through the error presenter, keeping the
     * output away from PHPUnit's output check.
     */
    private function quietly(callable $call): bool
    {
        ob_start();
        try {
            return $call();
        } finally {
            ob_end_clean();
        }
    }
}
