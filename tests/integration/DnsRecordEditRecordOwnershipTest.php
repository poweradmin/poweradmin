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
 * IDOR guard for DnsRecord::edit_record(). The record's zone must be derived
 * from the record id server-side; a caller-supplied zid must never be trusted.
 * Otherwise a "client" who owns one zone can pass that zone's id to satisfy the
 * ownership check while editing a record that lives in a zone it does not own.
 *
 * edit_record() writes with a bare UPDATE ... WHERE id=?, so the test runs
 * against a real in-memory database and asserts the foreign row is left intact.
 */
class DnsRecordEditRecordOwnershipTest extends TestCase
{
    private const ATTACKER_USER_ID = 100;
    private const ATTACKER_PERM_TEMPL_ID = 100;

    private const VICTIM_ZONE_ID = 2;
    private const ATTACKER_ZONE_ID = 3;
    private const VICTIM_RECORD_ID = 5;

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

        // The attacker is a client that may edit records only in zones it owns.
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (60, 'zone_content_edit_own_as_client')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::ATTACKER_PERM_TEMPL_ID . ", 'Client')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::ATTACKER_PERM_TEMPL_ID . ", 60)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::ATTACKER_USER_ID . ", 'attacker', " . self::ATTACKER_PERM_TEMPL_ID . ")");

        // Victim zone belongs to another user; the attacker only owns its own zone.
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, " . self::VICTIM_ZONE_ID . ", 1)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (2, " . self::ATTACKER_ZONE_ID . ", " . self::ATTACKER_USER_ID . ")");

        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (" . self::VICTIM_ZONE_ID . ", 'victim.example', 'MASTER')");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (" . self::ATTACKER_ZONE_ID . ", 'attacker.example', 'MASTER')");

        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (" . self::VICTIM_RECORD_ID . ", " . self::VICTIM_ZONE_ID . ", 'www.victim.example', 'A', '1.1.1.1', 3600, 0, 0)");
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    #[RunInSeparateProcess]
    public function testForgedZoneIdCannotEditForeignRecord(): void
    {
        // rid points at the victim's record, zid at the attacker's own zone.
        $this->assertFalse(
            $this->attemptForgedEdit(self::VICTIM_RECORD_ID, 'www'),
            'edit_record must reject a record whose real zone the caller does not own, even when the request carries an owned zid.'
        );

        $row = $this->db->queryRow("SELECT content, name FROM records WHERE id = " . self::VICTIM_RECORD_ID);
        $this->assertSame('1.1.1.1', $row['content'], 'The foreign record must be left untouched.');
        $this->assertSame('www.victim.example', $row['name'], 'The foreign record must be left untouched.');
    }

    #[RunInSeparateProcess]
    public function testEditRejectsUnknownRecordId(): void
    {
        $this->assertFalse($this->attemptForgedEdit(9999, 'ghost'));
    }

    /**
     * Drive edit_record() as the attacker with a request that always claims the
     * attacker's own zone id, and return edit_record()'s result. Output from the
     * error presenter is captured so it does not trip PHPUnit's output check.
     */
    private function attemptForgedEdit(int $rid, string $name): bool
    {
        $_SESSION['userid'] = self::ATTACKER_USER_ID;

        $record = [
            'rid' => $rid,
            'zid' => self::ATTACKER_ZONE_ID,
            'name' => $name,
            'type' => 'A',
            'content' => '6.6.6.6',
            'prio' => 0,
            'ttl' => 3600,
            'disabled' => 0,
        ];

        $dnsRecord = new DnsRecord($this->db, $this->makeConfig());

        ob_start();
        try {
            return $dnsRecord->edit_record($record);
        } finally {
            ob_end_clean();
        }
    }

    private function makeConfig(): AppConfiguration
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
        return $config;
    }
}
