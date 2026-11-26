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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Re-applying a template removes only records of the zone itself. A link row whose
 * record id was reused by another zone is dropped without deleting that record.
 */
class DnsRecordStaleTemplateLinkTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStaleLinkDoesNotDeleteRecordOfAnotherZone(): void
    {
        $_SESSION['userid'] = 1;

        $db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)");
        $db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, perm_templ INTEGER)");
        $db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)");
        $db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER)");
        $db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER)");
        $db->exec("CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)");
        $db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER)");
        $db->exec("INSERT INTO perm_items VALUES (1, 'user_is_ueberuser')");
        $db->exec("INSERT INTO perm_templ VALUES (1, 'admin')");
        $db->exec("INSERT INTO perm_templ_items VALUES (1, 1, 1)");
        $db->exec("INSERT INTO users VALUES (1, 1)");
        $db->exec("INSERT INTO domains VALUES (1, 'mine.example', 'MASTER'), (2, 'other.example', 'MASTER')");
        $db->exec("INSERT INTO zones VALUES (1, 1, 1, 5), (2, 2, 2, 0)");
        // Record 10 belongs to zone 2 but a stale link still ties it to zone 1; record 11 is a real linked record
        $db->exec("INSERT INTO records VALUES (10, 2, 'www.other.example', 'A', '192.0.2.9', 300, 0), (11, 1, 'www.mine.example', 'A', '192.0.2.1', 300, 0)");
        $db->exec("INSERT INTO records_zone_templ VALUES (1, 10, 5), (1, 11, 5)");

        $config = $this->createMock(AppConfiguration::class);
        $dnsRecord = new DnsRecord($db, $config);
        $dnsRecord->update_zone_records('sqlite', 300, 1, 5);

        $this->assertSame('1', (string)$db->query("SELECT COUNT(*) FROM records WHERE id = 10")->fetchColumn(), 'Record of the other zone is kept');
        $this->assertSame('0', (string)$db->query("SELECT COUNT(*) FROM records WHERE id = 11")->fetchColumn(), 'Own linked record is removed');
        $this->assertSame('0', (string)$db->query("SELECT COUNT(*) FROM records_zone_templ WHERE record_id = 10")->fetchColumn(), 'Stale link is dropped');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEditRightsWithoutZoneAddKeepTheTemplateRecords(): void
    {
        $_SESSION['userid'] = 1;

        $db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)");
        $db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, perm_templ INTEGER)");
        $db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)");
        $db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER)");
        $db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER)");
        $db->exec("CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)");
        $db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER)");
        $db->exec("INSERT INTO perm_items VALUES (1, 'zone_content_edit_own')");
        $db->exec("INSERT INTO perm_templ VALUES (1, 'editor')");
        $db->exec("INSERT INTO perm_templ_items VALUES (1, 1, 1)");
        $db->exec("INSERT INTO users VALUES (1, 1)");
        $db->exec("INSERT INTO domains VALUES (1, 'mine.example', 'MASTER')");
        $db->exec("INSERT INTO zones VALUES (1, 1, 1, 5)");
        $db->exec("INSERT INTO records VALUES (11, 1, 'mine.example', 'NS', 'ns1.mine.example', 300, 0)");
        $db->exec("INSERT INTO records_zone_templ VALUES (1, 11, 5)");

        $config = $this->createMock(AppConfiguration::class);
        $dnsRecord = new DnsRecord($db, $config);
        ob_start();
        $dnsRecord->update_zone_records('sqlite', 300, 1, 5);
        ob_end_clean();

        $this->assertSame('1', (string)$db->query("SELECT COUNT(*) FROM records WHERE id = 11")->fetchColumn(), 'Template record is kept');
        $this->assertSame('1', (string)$db->query("SELECT COUNT(*) FROM records_zone_templ")->fetchColumn(), 'Link is kept');
    }
}
