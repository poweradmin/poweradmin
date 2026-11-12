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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DatabaseConsistencyService;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\SqlDynamicDnsRepository;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;

/**
 * SQLite reuses record ids, so a record_comment_links row left behind by a delete
 * would attach an unrelated comment to the next record that gets the same id.
 */
class RecordDeleteCommentLinkCleanupTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT, account TEXT, catalog TEXT)",
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)",
                "CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)",
                "CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER)",
                "CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, modified_at INTEGER, account TEXT, comment TEXT)",
                "CREATE TABLE record_comment_links (record_id TEXT PRIMARY KEY, comment_id INTEGER UNIQUE)",
                "CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ_api (id INTEGER PRIMARY KEY, domain_id INTEGER, record_id TEXT, zone_templ_id INTEGER)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER DEFAULT 0)",
                "CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE api_key_zones (api_key_id INTEGER, zone_id INTEGER)",
                "INSERT INTO domains (id, name, type) VALUES (1, 'a.example', 'NATIVE')",
                "INSERT INTO records (id, domain_id, name, type, content) VALUES
                    (10, 1, 'a.example', 'SOA', 'ns. h. 1 2 3 4 5'),
                    (11, 1, 'a.example', 'SOA', 'ns. h. 2 2 3 4 5'),
                    (12, 1, 'www.a.example', 'A', '192.0.2.1')",
                "INSERT INTO records (id, domain_id, name, type, content) VALUES
                    (13, 1, 'www.a.example', 'A', '192.0.2.2')",
                "INSERT INTO comments (id, domain_id, name, type, comment) VALUES
                    (100, 1, 'a.example', 'SOA', 'c10'), (101, 1, 'a.example', 'SOA', 'c11'),
                    (102, 1, 'www.a.example', 'A', 'c12'), (103, 1, 'www.a.example', 'A', 'c13')",
                "INSERT INTO record_comment_links (record_id, comment_id) VALUES ('10', 100), ('11', 101), ('12', 102), ('13', 103)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }

    /** @return string[] */
    private function links(): array
    {
        return $this->db->query("SELECT record_id FROM record_comment_links ORDER BY record_id")->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return string[] */
    private function comments(): array
    {
        return $this->db->query("SELECT comment FROM comments ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testDynamicDnsDeleteDropsTheLinkAndComment(): void
    {
        $repo = new SqlDynamicDnsRepository($this->db, $this->createMock(SOARecordManagerInterface::class), 'records', 'domains');
        $repo->deleteDnsRecord(12);

        $this->assertSame(['10', '11', '13'], $this->links());
        $this->assertSame(['c10', 'c11', 'c13'], $this->comments());
    }

    public function testBackendProviderDeleteDropsTheLinkAndComment(): void
    {
        $provider = new SqlDnsBackendProvider($this->db, ConfigurationManager::getInstance());
        $provider->deleteRecord(12);

        $this->assertSame(['10', '11', '13'], $this->links());
        $this->assertSame(['c10', 'c11', 'c13'], $this->comments());
    }

    public function testBackendProviderZoneDeletesDropEveryLinkAndComment(): void
    {
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (2, 'b.example', 'NATIVE')");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content) VALUES (30, 2, 'b.example', 'A', '192.0.2.3')");
        $this->db->exec("INSERT INTO comments (id, domain_id, name, type, comment) VALUES (130, 2, 'b.example', 'A', 'c30')");
        $this->db->exec("INSERT INTO record_comment_links (record_id, comment_id) VALUES ('30', 130)");

        $provider = new SqlDnsBackendProvider($this->db, ConfigurationManager::getInstance());
        $this->assertTrue($provider->deleteRecordsByDomainId(1));
        $this->assertSame(['30'], $this->links());
        $this->assertSame(['c30'], $this->comments());

        $this->assertTrue($provider->deleteZone(2, 'b.example'));
        $this->assertSame([], $this->links());
        $this->assertSame([], $this->comments());
    }

    public function testConsistencyDeletesDropTheLinks(): void
    {
        $checks = $this->consistency();
        $this->assertTrue($checks->fixDuplicateSOA(1));
        $this->assertSame(['10', '12', '13'], $this->links());
        $this->assertSame(['c10', 'c12', 'c13'], $this->comments());

        $this->assertTrue($checks->deleteSlaveZone(1));
        $this->assertSame([], $this->links());
        $this->assertSame([], $this->comments());
    }

    public function testOrphanedRecordDeleteDropsTheLink(): void
    {
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content) VALUES (20, 99, 'x.gone', 'A', '192.0.2.9')");
        $this->db->exec("INSERT INTO comments (id, domain_id, name, type, comment) VALUES (120, 99, 'x.gone', 'A', 'c20')");
        $this->db->exec("INSERT INTO record_comment_links (record_id, comment_id) VALUES ('20', 120)");

        $this->assertTrue($this->consistency()->deleteOrphanedRecord(20));
        $this->assertSame(['10', '11', '12', '13'], $this->links());
        $this->assertNotContains('c20', $this->comments());
    }

    private function consistency(): DatabaseConsistencyService
    {
        $provider = $this->createMock(DnsBackendProvider::class);
        $provider->method('isApiBackend')->willReturn(false);
        $config = ConfigurationManager::getInstance();
        $config->initialize();

        return new DatabaseConsistencyService($this->db, $config, $provider);
    }
}
