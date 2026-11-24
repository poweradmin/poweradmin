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

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use ReflectionClass;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Applying a template skips the records that would break the CNAME rule, keeps the
 * rest, and leaves the zone out of sync so the skip stays visible.
 */
class DomainManagerTemplateCnameConflictTest extends SqliteIntegrationTestCase
{
    private const ZONE_ID = 555;
    private const TEMPLATE_ID = 9;

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
        $this->db->exec("CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER NOT NULL, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0, last_synced TEXT, template_last_modified TEXT)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (42, 'zone_master_add')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::ADMIN_PERM_TEMPL_ID . ", 42)");
        $this->db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (" . self::TEMPLATE_ID . ", 'tpl', '', 0)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, " . self::ZONE_ID . ", " . self::ADMIN_USER_ID . ", 0)");
    }

    #[RunInSeparateProcess]
    public function testRecordsBesideAStoredCnameAreSkippedAndTheRestWritten(): void
    {
        $this->storeRecord('www.example.com', 'CNAME', 'target.example.net');
        $this->templateRecord('www', 'A', '192.0.2.10');
        $this->templateRecord('mail', 'A', '192.0.2.20');

        $this->assertFalse($this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID));
        $this->assertSame(['CNAME'], $this->typesAt('www.example.com'));
        $this->assertSame(['A'], $this->typesAt('mail.example.com'));
        $this->assertSame(1, $this->needsSync());
    }

    /**
     * @return array<string, array{list<array{string, string}>}>
     */
    public static function cnameBesideTemplateEntryProvider(): array
    {
        return [
            'CNAME first' => [[['CNAME', 'target.example.net'], ['TXT', 'v=spf1 -all']]],
            'CNAME last' => [[['TXT', 'v=spf1 -all'], ['CNAME', 'target.example.net']]],
        ];
    }

    /**
     * @param list<array{string, string}> $entries
     */
    #[RunInSeparateProcess]
    #[DataProvider('cnameBesideTemplateEntryProvider')]
    public function testATemplateCnameYieldsToAnotherTemplateEntryInAnyOrder(array $entries): void
    {
        foreach ($entries as [$type, $content]) {
            $this->templateRecord('alias', $type, $content);
        }

        $this->assertFalse($this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID));
        $this->assertSame(['TXT'], $this->typesAt('alias.example.com'));
    }

    #[RunInSeparateProcess]
    public function testTheApexNsIsWrittenBesideAStoredApexCname(): void
    {
        $this->storeRecord('example.com', 'CNAME', 'target.example.net');
        $this->templateRecord('[ZONE]', 'NS', 'ns1.example.net');

        $this->assertTrue($this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID));
        $this->assertSame(['CNAME', 'NS'], $this->typesAt('example.com'));
    }

    #[RunInSeparateProcess]
    public function testATemplateWithoutConflictsMarksTheZoneSynced(): void
    {
        $this->storeRecord('www.example.com', 'CNAME', 'target.example.net');
        $this->templateRecord('mail', 'A', '192.0.2.20');
        $this->templateRecord('alias', 'CNAME', 'target.example.net');

        $this->assertTrue($this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID));
        $this->assertSame(['CNAME'], $this->typesAt('alias.example.com'));
        $this->assertSame(0, $this->needsSync());
    }

    #[RunInSeparateProcess]
    public function testAStaleLinkToAnotherZonesRecordSurvivesAReapplyWithoutDeletingIt(): void
    {
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (77, 999, 'victim.other.test', 'A', '192.0.2.99', 3600, 0)");
        $this->db->exec("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (" . self::ZONE_ID . ", 77, " . self::TEMPLATE_ID . ")");
        $this->templateRecord('mail', 'A', '192.0.2.20');

        $this->assertTrue($this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM records WHERE id = 77 AND domain_id = 999")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM records_zone_templ WHERE record_id = 77")->fetchColumn());
    }

    private function storeRecord(string $name, string $type, string $content): void
    {
        $stmt = $this->db->prepare("INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES (:zone, :name, :type, :content, 3600, 0)");
        $stmt->execute([':zone' => self::ZONE_ID, ':name' => $name, ':type' => $type, ':content' => $content]);
    }

    private function templateRecord(string $name, string $type, string $content): void
    {
        $stmt = $this->db->prepare("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (:templ, :name, :type, :content, 300, 0)");
        $stmt->execute([':templ' => self::TEMPLATE_ID, ':name' => $name, ':type' => $type, ':content' => $content]);
    }

    /**
     * @return list<string>
     */
    private function typesAt(string $name): array
    {
        $stmt = $this->db->prepare("SELECT type FROM records WHERE domain_id = :zone AND name = :name ORDER BY type");
        $stmt->execute([':zone' => self::ZONE_ID, ':name' => $name]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function needsSync(): int
    {
        return (int)$this->db->query("SELECT needs_sync FROM zone_template_sync WHERE zone_id = 1")->fetchColumn();
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
            $stmt = $this->db->prepare("SELECT name, type FROM records WHERE domain_id = :zone");
            $stmt->execute([':zone' => $zoneId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        });

        return new DomainManager(
            $this->db,
            $config,
            $soa,
            $repo,
            $backend,
            null,
            $this->createMock(RecordChangeLogger::class)
        );
    }
}
