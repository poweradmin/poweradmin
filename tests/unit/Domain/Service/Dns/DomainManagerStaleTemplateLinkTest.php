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

namespace Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\SqlDynamicDnsRepository;
use ReflectionClass;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * A template link whose record id now sits in another zone (SQLite reuses freed ids)
 * is dropped on re-apply without touching that record, and record deletes unlink.
 */
class DomainManagerStaleTemplateLinkTest extends SqliteIntegrationTestCase
{
    private const ZONE_ID = 555;
    private const OTHER_ZONE_ID = 777;
    private const TEMPLATE_ID = 9;
    private const FOREIGN_RECORD_ID = 4242;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE records_zone_templ_api (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id TEXT, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL, content TEXT NOT NULL, ttl INTEGER NOT NULL, prio INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, descr TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, comment TEXT)");
        $this->db->exec("CREATE TABLE record_comment_links (record_id TEXT PRIMARY KEY, comment_id INTEGER UNIQUE)");
        $this->db->exec("CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER NOT NULL, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0, last_synced TEXT, template_last_modified TEXT)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (42, 'zone_master_add')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::ADMIN_PERM_TEMPL_ID . ", 42)");
        $this->db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (" . self::TEMPLATE_ID . ", 'tpl', '', 0)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, " . self::ZONE_ID . ", " . self::ADMIN_USER_ID . ", 0)");
        $this->db->exec("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (" . self::TEMPLATE_ID . ", 'www', 'A', '192.0.2.10', 300, 0)");

        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (" . self::FOREIGN_RECORD_ID . ", " . self::OTHER_ZONE_ID . ", 'keep.other.test', 'A', '198.51.100.7', 3600, 0)");
        $this->db->exec("INSERT INTO comments (id, domain_id, name, type, comment) VALUES (1, " . self::OTHER_ZONE_ID . ", 'keep.other.test', 'A', 'keep me')");
        $this->db->exec("INSERT INTO record_comment_links (record_id, comment_id) VALUES ('" . self::FOREIGN_RECORD_ID . "', 1)");
        $this->db->exec("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (" . self::ZONE_ID . ", " . self::FOREIGN_RECORD_ID . ", " . self::TEMPLATE_ID . ")");
    }

    #[RunInSeparateProcess]
    public function testAStaleLinkToAnotherZonesRecordIsDroppedAndTheRecordKept(): void
    {
        $this->assertTrue($this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID));

        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM records WHERE id = " . self::FOREIGN_RECORD_ID . " AND domain_id = " . self::OTHER_ZONE_ID));
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM comments WHERE id = 1"));
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM record_comment_links WHERE record_id = '" . self::FOREIGN_RECORD_ID . "'"));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM records_zone_templ WHERE record_id = " . self::FOREIGN_RECORD_ID));
    }

    #[RunInSeparateProcess]
    public function testReplacingTheSoaDropsItsTemplateLink(): void
    {
        $this->db->exec("DELETE FROM records_zone_templ");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (91, " . self::ZONE_ID . ", 'example.com', 'SOA', 'ns1.example.com hm.example.com 1 2 3 4 5', 3600, 0)");
        $this->db->exec("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (" . self::TEMPLATE_ID . ", '[ZONE]', 'SOA', 'ns1.example.com hm.example.com 1 2 3 4 5', 3600, 0)");
        $this->db->exec("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (" . self::ZONE_ID . ", 91, 8)");

        $this->assertTrue($this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID));

        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM records_zone_templ WHERE record_id = 91"));
    }

    public function testDeletingADdnsRecordDropsItsTemplateLink(): void
    {
        $repository = new SqlDynamicDnsRepository($this->db, $this->createMock(DnsRecord::class), 'records');
        $repository->deleteDnsRecord(self::FOREIGN_RECORD_ID);

        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM records_zone_templ WHERE record_id = " . self::FOREIGN_RECORD_ID));
    }

    private function scalar(string $sql): int
    {
        return (int)$this->db->query($sql)->fetchColumn();
    }

    private function makeDomainManager(): DomainManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $reflection->getProperty('settings')->setValue($config, [
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => [
                'ttl' => 3600,
                'ns1' => 'ns1.example.com', 'ns2' => 'ns2.example.com', 'ns3' => '', 'ns4' => '',
                'hostmaster' => 'hostmaster.example.com',
                'soa_refresh' => 28800, 'soa_retry' => 7200, 'soa_expire' => 604800, 'soa_minimum' => 86400,
            ],
        ]);
        $reflection->getProperty('initialized')->setValue($config, true);

        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->method('getSOARecord')->willReturn('');
        $repo = $this->createMock(DomainRepositoryInterface::class);
        $repo->method('getDomainType')->willReturn('MASTER');
        $repo->method('getDomainNameById')->willReturn('example.com');

        $backend = $this->dnsBackendStub(false);
        $backend->method('getRecordsByZoneId')->willReturnCallback(function (int $zoneId): array {
            $stmt = $this->db->prepare("SELECT name, type, content FROM records WHERE domain_id = :zone");
            $stmt->execute([':zone' => $zoneId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        });

        return new DomainManager($this->db, $config, $soa, $repo, $backend);
    }
}
