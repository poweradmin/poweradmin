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

namespace Poweradmin\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Application\Service\RepositoryFactory;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\ZoneTemplateApplier;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\ZoneTemplatePlaceholders;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * DomainManager::addDomain materialises a zone template: every template record
 * reaches the backend with its placeholders resolved, is linked back to the
 * template, and the zone is registered for template sync. Until now only the
 * Playwright suite covered this path.
 */
class DomainManagerTemplateMaterialisationTest extends SqliteIntegrationTestCase
{
    private const TEMPLATE_ID = 9;
    private const NEW_DOMAIN_ID = 77;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, owner INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL, content TEXT NOT NULL, ttl INTEGER NOT NULL, prio INTEGER NOT NULL)");
        $this->db->exec("INSERT INTO zone_templ (id, name, owner) VALUES (" . self::TEMPLATE_ID . ", 'Standard', 0)");
    }

    #[RunInSeparateProcess]
    public function testTemplateRecordsReachTheBackendWithPlaceholdersResolved(): void
    {
        $this->seedTemplateRecords([
            ['[ZONE]', 'SOA', '[NS1] [HOSTMASTER] [SERIAL] 28800 7200 604800 86400', 0, 0],
            ['[ZONE]', 'NS', '[NS1]', 86400, 0],
            ['[ZONE]', 'MX', 'mail.[ZONE]', 0, 10],
        ]);

        $backend = $this->dnsBackendStub(false);
        $backend->method('createZone')->with('new.example', 'MASTER', '')->willReturn(self::NEW_DOMAIN_ID);
        $written = [];
        $backend->method('addRecordGetId')->willReturnCallback(function (int $domainId, string $name, string $type, string $content, int $ttl, int $prio) use (&$written) {
            $written[] = [$domainId, $name, $type, $content, $ttl, $prio];
            return 500 + count($written);
        });

        $result = $this->makeDomainManager($backend)->addDomain($this->db, 'new.example', self::ADMIN_USER_ID, 'MASTER', '', self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame(self::NEW_DOMAIN_ID, $result->zoneId);

        $this->assertCount(3, $written);
        [, $soaName, $soaType, $soaContent, $soaTtl] = $written[0];
        $this->assertSame(['new.example', 'SOA'], [$soaName, $soaType]);
        $this->assertMatchesRegularExpression('/^ns1\.example hostmaster\.example \d{10} 28800 7200 604800 86400$/', $soaContent);
        // A zero template TTL falls back to dns.ttl.
        $this->assertSame(3600, $soaTtl);
        $this->assertSame([self::NEW_DOMAIN_ID, 'new.example', 'NS', 'ns1.example', 86400, 0], $written[1]);
        $this->assertSame([self::NEW_DOMAIN_ID, 'new.example', 'MX', 'mail.new.example', 3600, 10], $written[2]);
    }

    #[RunInSeparateProcess]
    public function testZoneRowLinksAndSyncStateAreWrittenForTheTemplate(): void
    {
        $this->seedTemplateRecords([
            ['[ZONE]', 'NS', '[NS1]', 86400, 0],
            ['www.[ZONE]', 'A', '192.0.2.10', 300, 0],
        ]);

        $backend = $this->dnsBackendStub(false);
        $backend->method('createZone')->willReturn(self::NEW_DOMAIN_ID);
        $backend->method('addRecordGetId')->willReturnOnConsecutiveCalls(501, 502);

        $result = $this->makeDomainManager($backend)->addDomain($this->db, 'new.example', self::ADMIN_USER_ID, 'MASTER', '', self::TEMPLATE_ID, [4]);

        $this->assertTrue($result->success);
        $this->assertSame(
            [['domain_id' => self::NEW_DOMAIN_ID, 'owner' => self::ADMIN_USER_ID, 'zone_templ_id' => self::TEMPLATE_ID]],
            $this->rows('SELECT domain_id, owner, zone_templ_id FROM zones')
        );
        $this->assertSame(
            [
                ['domain_id' => self::NEW_DOMAIN_ID, 'record_id' => 501, 'zone_templ_id' => self::TEMPLATE_ID],
                ['domain_id' => self::NEW_DOMAIN_ID, 'record_id' => 502, 'zone_templ_id' => self::TEMPLATE_ID],
            ],
            $this->rows('SELECT domain_id, record_id, zone_templ_id FROM records_zone_templ ORDER BY record_id')
        );
        $this->assertSame([], $this->rows('SELECT domain_id FROM records_zone_templ_api'));
        // Sync rows key on zones.id, which is not the PowerDNS domain id in SQL mode.
        $zonesRowId = (int)$this->db->query('SELECT id FROM zones')->fetchColumn();
        $this->assertNotSame(self::NEW_DOMAIN_ID, $zonesRowId);
        $this->assertSame(
            [['zone_id' => $zonesRowId, 'zone_templ_id' => self::TEMPLATE_ID, 'needs_sync' => 0]],
            $this->rows('SELECT zone_id, zone_templ_id, needs_sync FROM zone_template_sync')
        );
        $this->assertSame([['group_id' => 4]], $this->rows('SELECT group_id FROM zones_groups'));
    }

    #[RunInSeparateProcess]
    public function testIpv4ReverseZoneSkipsTemplateRecordTypesThatCannotLiveThere(): void
    {
        $this->seedTemplateRecords([
            ['[ZONE]', 'NS', '[NS1]', 86400, 0],
            ['[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10],
            ['[ZONE]', 'A', '192.0.2.1', 3600, 0],
        ]);

        $backend = $this->dnsBackendStub(false);
        $backend->method('createZone')->willReturn(self::NEW_DOMAIN_ID);
        $types = [];
        $backend->method('addRecordGetId')->willReturnCallback(function (int $domainId, string $name, string $type) use (&$types) {
            $types[] = $type;
            return 600 + count($types);
        });

        $result = $this->makeDomainManager($backend)->addDomain($this->db, '2.0.192.in-addr.arpa', self::ADMIN_USER_ID, 'MASTER', '', self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame(['NS'], $types);
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM records_zone_templ')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function testBackendRefusingATemplateRecordRollsTheZoneBack(): void
    {
        $this->seedTemplateRecords([
            ['[ZONE]', 'NS', '[NS1]', 86400, 0],
            ['www.[ZONE]', 'A', 'not-an-address', 300, 0],
        ]);

        $backend = $this->dnsBackendStub(false);
        $backend->method('createZone')->willReturn(self::NEW_DOMAIN_ID);
        $backend->method('addRecordGetId')->willReturnOnConsecutiveCalls(501, null);
        $backend->expects($this->once())->method('deleteZone')->with(self::NEW_DOMAIN_ID, 'new.example')->willReturn(true);

        $result = $this->makeDomainManager($backend)->addDomain($this->db, 'new.example', self::ADMIN_USER_ID, 'MASTER', '', self::TEMPLATE_ID, [4]);

        $this->assertFalse($result->success);
        $this->assertSame('Failed to create A record for zone.', $result->message);
        $this->assertSame([], $this->rows('SELECT id FROM zones'));
        $this->assertSame([], $this->rows('SELECT id FROM zones_groups'));
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ'));
    }

    #[RunInSeparateProcess]
    public function testApiBackendFillsTheExistingZoneRowAndLinksByStringId(): void
    {
        $this->seedTemplateRecords([
            ['[ZONE]', 'NS', '[NS1]', 86400, 0],
        ]);

        $backend = $this->dnsBackendStub(true);
        // In API mode createZone() has already inserted the zones row and set domain_id = id.
        $backend->method('createZone')->willReturnCallback(function (): int {
            $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (" . self::NEW_DOMAIN_ID . ", " . self::NEW_DOMAIN_ID . ", NULL, 0)");
            return self::NEW_DOMAIN_ID;
        });
        $backend->method('addRecordGetId')->willReturn('new.example./NS/new.example.');

        $result = $this->makeDomainManager($backend)->addDomain($this->db, 'new.example', self::ADMIN_USER_ID, 'MASTER', '', self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame(
            [['id' => self::NEW_DOMAIN_ID, 'owner' => self::ADMIN_USER_ID, 'zone_templ_id' => self::TEMPLATE_ID]],
            $this->rows('SELECT id, owner, zone_templ_id FROM zones')
        );
        $this->assertSame(
            [['zone_id' => self::NEW_DOMAIN_ID, 'zone_templ_id' => self::TEMPLATE_ID]],
            $this->rows('SELECT zone_id, zone_templ_id FROM zone_template_sync')
        );
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ'));
        $this->assertSame(
            [['domain_id' => self::NEW_DOMAIN_ID, 'record_id' => 'new.example./NS/new.example.', 'zone_templ_id' => self::TEMPLATE_ID]],
            $this->rows('SELECT domain_id, record_id, zone_templ_id FROM records_zone_templ_api')
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: int, 4: int}> $records name, type, content, ttl, prio
     */
    private function seedTemplateRecords(array $records): void
    {
        $stmt = $this->db->prepare("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($records as [$name, $type, $content, $ttl, $prio]) {
            $stmt->execute([self::TEMPLATE_ID, $name, $type, $content, $ttl, $prio]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function makeDomainManager(DnsBackendProviderInterface $backend): DomainManager
    {
        $config = $this->primeConfigurationManager([
            'dns' => [
                'ns1' => 'ns1.example', 'ns2' => 'ns2.example', 'ns3' => '', 'ns4' => '',
                'hostmaster' => 'hostmaster.example', 'ttl' => 3600,
                'soa_refresh' => 28800, 'soa_retry' => 7200, 'soa_expire' => 604800, 'soa_minimum' => 86400,
            ],
        ]);

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainNameById')->willReturn('new.example');

        return new DomainManager(
            $this->db,
            $config,
            $domainRepository,
            new RepositoryFactory($this->db, $config, $backend),
            $backend,
            $this->permissionService($config),
            new DbUserRepository($this->db, $config),
            $this->createMock(RecordChangeWriterInterface::class),
            $this->createMock(ZoneTemplateApplier::class),
            new DbZoneTemplateRepository($this->db, $config, $backend),
            new ZoneTemplatePlaceholders($config)
        );
    }
}
