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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RepositoryFactoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\ZoneTemplateApplier;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\ZoneTemplatePlaceholders;
use Poweradmin\Domain\Service\ZoneTemplateSyncService;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;
use TestHelpers\PermissionServiceTestCase;

/**
 * updateZoneRecords() re-applies a template to an existing zone: the records the
 * previous application wrote are removed (by numeric id on SQL backends, by
 * encoded id on the API backend), the template records are written and linked,
 * the zones row is repointed and the sync state reconciled.
 */
#[CoversClass(DomainManager::class)]
#[CoversClass(ZoneTemplateApplier::class)]
class DomainManagerUpdateZoneRecordsTest extends PermissionServiceTestCase
{
    private const CALLER_ID = 7;
    private const ZONE_ID = 77;
    private const OLD_TEMPLATE_ID = 5;
    private const TEMPLATE_ID = 9;
    private const TTL = 3600;

    private PDO $db;
    private FakeConfiguration $config;
    private DnsBackendProviderInterface&MockObject $backend;
    private SOARecordManagerInterface&MockObject $soa;
    private RecordChangeWriterInterface&MockObject $changeLogger;
    private string $zoneType = 'MASTER';
    private string $zoneName = 'old.example';

    /** @var list<array<string, mixed>> */
    private array $loggedDeletes = [];

    /** @var list<array<string, mixed>> */
    private array $loggedCreates = [];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)",
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)",
                "CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ_api (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id TEXT, zone_templ_id INTEGER)",
                "CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER NOT NULL, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0, last_synced TEXT, template_last_modified TEXT)",
                "CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL, content TEXT NOT NULL, ttl INTEGER NOT NULL, prio INTEGER NOT NULL)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $this->config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => [
                'ns1' => 'ns1.example', 'ns2' => 'ns2.example', 'ns3' => '', 'ns4' => '',
                'hostmaster' => 'hostmaster.example', 'ttl' => self::TTL,
                'soa_refresh' => 28800, 'soa_retry' => 7200, 'soa_expire' => 604800, 'soa_minimum' => 86400,
            ],
        ]);

        $this->soa = $this->createMock(SOARecordManagerInterface::class);
        $this->soa->method('getSOARecord')->with(self::ZONE_ID)->willReturn('');
        $this->soa->method('getUpdatedSOARecord')->willReturn('');

        $this->changeLogger = $this->createMock(RecordChangeWriterInterface::class);
        $this->changeLogger->method('logRecordDelete')->willReturnCallback(function (array $record, ?int $zoneId): void {
            $this->loggedDeletes[] = ['zone' => $zoneId] + $record;
        });
        $this->changeLogger->method('logRecordCreate')->willReturnCallback(function (array $record, ?int $zoneId): void {
            $this->loggedCreates[] = ['zone' => $zoneId] + $record;
        });
    }

    public function testReadOnlyZoneIsLeftUntouched(): void
    {
        $this->zoneType = 'SLAVE';
        $this->seedZone(self::OLD_TEMPLATE_ID);
        $this->backend = $this->sqlBackend();
        $this->backend->expects($this->never())->method('addRecordGetId');

        $result = $this->manager()->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame([['zone_templ_id' => self::OLD_TEMPLATE_ID]], $this->rows('SELECT zone_templ_id FROM zones'));
    }

    public function testSqlBackendReplacesTheLinkedRecordsAndReconcilesSyncState(): void
    {
        $zonesRowId = $this->seedZone(self::TEMPLATE_ID);
        $this->db->exec("INSERT INTO zone_template_sync (zone_id, zone_templ_id, needs_sync) VALUES ($zonesRowId, " . self::OLD_TEMPLATE_ID . ", 1)");
        $this->db->exec("INSERT INTO zone_template_sync (zone_id, zone_templ_id, needs_sync) VALUES ($zonesRowId, " . self::TEMPLATE_ID . ", 1)");
        // Two records came from an earlier application of this template, one was authored by hand.
        $this->seedRecord(101, 'old.example', 'A', '192.0.2.1', linkedTo: self::TEMPLATE_ID);
        $this->seedRecord(102, 'old.example', 'MX', 'mail.old.example', prio: 10, linkedTo: self::TEMPLATE_ID);
        $this->seedRecord(103, 'manual.old.example', 'A', '192.0.2.99');
        $this->seedRecord(104, 'old.example', 'SOA', 'ns1.example hostmaster.example 2024010100 1 2 3 4');
        $this->seedTemplateRecords([
            ['[ZONE]', 'SOA', '[NS1] [HOSTMASTER] [SERIAL]', 0, 0],
            ['www.[ZONE]', 'A', '192.0.2.10', 0, 0],
            ['[ZONE]', 'MX', 'mail.[ZONE]', 600, 20],
        ]);

        $this->backend = $this->sqlBackend();
        $this->backend->method('recordExists')->willReturn(false);
        $written = [];
        $this->backend->method('addRecordGetId')->willReturnCallback(function (int $domainId, string $name, string $type, string $content, int $ttl, int $prio) use (&$written) {
            $this->assertTrue($this->db->inTransaction(), 'SQL backend writes join the open transaction');
            $written[] = [$domainId, $name, $type, $content, $ttl, $prio];
            return 500 + count($written);
        });

        $result = $this->manager()->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame(self::ZONE_ID, $result->zoneId);
        $this->assertSame([
            [self::ZONE_ID, 'old.example', 'SOA', 'ns1.example hostmaster.example ' . date('Ymd') . '00 28800 7200 604800 86400', self::TTL, 0],
            [self::ZONE_ID, 'old.example', 'MX', 'mail.old.example', 600, 20],
            [self::ZONE_ID, 'www.old.example', 'A', '192.0.2.10', self::TTL, 0],
        ], $written);
        // The hand-authored record survives; the old template records and the SOA are gone.
        $this->assertSame([['id' => 103]], $this->rows('SELECT id FROM records ORDER BY id'));
        $this->assertSame(
            [
                ['record_id' => 501, 'zone_templ_id' => self::TEMPLATE_ID],
                ['record_id' => 502, 'zone_templ_id' => self::TEMPLATE_ID],
                ['record_id' => 503, 'zone_templ_id' => self::TEMPLATE_ID],
            ],
            $this->rows('SELECT record_id, zone_templ_id FROM records_zone_templ ORDER BY record_id')
        );
        $this->assertSame([['zone_templ_id' => self::TEMPLATE_ID]], $this->rows('SELECT zone_templ_id FROM zones'));
        $this->assertSame(
            [['zone_id' => $zonesRowId, 'zone_templ_id' => self::TEMPLATE_ID, 'needs_sync' => 0]],
            $this->rows('SELECT zone_id, zone_templ_id, needs_sync FROM zone_template_sync')
        );
        $this->assertSame([101, 102, 104], array_column($this->loggedDeletes, 'id'));
        $this->assertSame([self::ZONE_ID, self::ZONE_ID, self::ZONE_ID], array_column($this->loggedDeletes, 'zone'));
        $this->assertSame([501, 502, 503], array_column($this->loggedCreates, 'id'));
        $this->assertSame(['SOA', 'MX', 'A'], array_column($this->loggedCreates, 'type'));
        $this->assertFalse($this->db->inTransaction());
    }

    public function testSwitchingTemplatesLeavesThePreviousTemplateRecordsInPlace(): void
    {
        $this->seedZone(self::OLD_TEMPLATE_ID);
        $this->seedRecord(101, 'old.example', 'A', '192.0.2.1', linkedTo: self::OLD_TEMPLATE_ID);
        $this->seedTemplateRecords([['www.[ZONE]', 'A', '192.0.2.10', 300, 0]]);
        $this->backend = $this->sqlBackend();
        $this->backend->method('recordExists')->willReturn(false);
        $this->backend->method('addRecordGetId')->willReturn(501);

        $result = $this->manager()->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        // Only records linked to the template being applied are removed.
        $this->assertSame([['id' => 101]], $this->rows('SELECT id FROM records'));
        $this->assertSame(
            [
                ['record_id' => 101, 'zone_templ_id' => self::OLD_TEMPLATE_ID],
                ['record_id' => 501, 'zone_templ_id' => self::TEMPLATE_ID],
            ],
            $this->rows('SELECT record_id, zone_templ_id FROM records_zone_templ ORDER BY record_id')
        );
        $this->assertSame([['zone_templ_id' => self::TEMPLATE_ID]], $this->rows('SELECT zone_templ_id FROM zones'));
        $this->assertSame([], $this->loggedDeletes);
    }

    public function testSoaContentComesFromTheCurrentSerialWhenTheZoneHasOne(): void
    {
        $this->seedZone(0);
        $this->seedTemplateRecords([['[ZONE]', 'SOA', '[NS1] [HOSTMASTER] [SERIAL]', 0, 0]]);
        $this->soa = $this->createMock(SOARecordManagerInterface::class);
        $this->soa->method('getSOARecord')->willReturn('ns1.example hostmaster.example 2024010101 1 2 3 4');
        $this->soa->method('getUpdatedSOARecord')->with('ns1.example hostmaster.example 2024010101 1 2 3 4')
            ->willReturn('ns1.example hostmaster.example 2024010102 1 2 3 4');

        $this->backend = $this->sqlBackend();
        $this->backend->method('recordExists')->willReturn(false);
        $this->backend->expects($this->once())->method('addRecordGetId')
            ->with(self::ZONE_ID, 'old.example', 'SOA', 'ns1.example hostmaster.example 2024010102 1 2 3 4', self::TTL, 0)
            ->willReturn(501);

        $this->assertTrue($this->manager()->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID)->success);
    }

    public function testRecordsAlreadyInTheZoneAreNotWrittenTwice(): void
    {
        $this->seedZone(0);
        $this->seedTemplateRecords([['www.[ZONE]', 'A', '192.0.2.10', 300, 0]]);
        $this->backend = $this->sqlBackend();
        $this->backend->method('recordExists')->with(self::ZONE_ID, 'www.old.example', 'A', '192.0.2.10')->willReturn(true);
        $this->backend->expects($this->never())->method('addRecordGetId');

        $result = $this->manager()->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ'));
        $this->assertSame([['zone_templ_id' => self::TEMPLATE_ID]], $this->rows('SELECT zone_templ_id FROM zones'));
    }

    public function testBackendRefusingARecordSkipsItWithoutFailingTheApplication(): void
    {
        $this->seedZone(0);
        $this->seedTemplateRecords([
            ['www.[ZONE]', 'A', 'not-an-address', 300, 0],
            ['[ZONE]', 'NS', '[NS1]', 300, 0],
        ]);
        $this->backend = $this->sqlBackend();
        $this->backend->method('recordExists')->willReturn(false);
        $this->backend->method('addRecordGetId')->willReturnCallback(fn(int $domainId, string $name, string $type) => $type === 'A' ? null : 501);

        $result = $this->manager()->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame([['record_id' => 501]], $this->rows('SELECT record_id FROM records_zone_templ'));
        $this->assertSame([501], array_column($this->loggedCreates, 'id'));
    }

    public function testIpv4ReverseZoneSkipsTemplateRecordTypesThatCannotLiveThere(): void
    {
        $this->seedZone(0, '2.0.192.in-addr.arpa');
        $this->seedTemplateRecords([
            ['[ZONE]', 'NS', '[NS1]', 86400, 0],
            ['[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10],
            ['[ZONE]', 'A', '192.0.2.1', 3600, 0],
        ]);
        $this->backend = $this->sqlBackend();
        $this->backend->method('recordExists')->willReturn(false);
        $types = [];
        $this->backend->method('addRecordGetId')->willReturnCallback(function (int $domainId, string $name, string $type) use (&$types) {
            $types[] = $type;
            return 600 + count($types);
        });

        $this->assertTrue($this->manager()->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID)->success);
        $this->assertSame(['NS'], $types);
    }

    public function testUnlinkingRemovesNothingAndClearsTheSyncRows(): void
    {
        $zonesRowId = $this->seedZone(self::OLD_TEMPLATE_ID);
        $this->db->exec("INSERT INTO zone_template_sync (zone_id, zone_templ_id, needs_sync) VALUES ($zonesRowId, " . self::OLD_TEMPLATE_ID . ", 1)");
        $this->seedRecord(101, 'old.example', 'A', '192.0.2.1', linkedTo: self::OLD_TEMPLATE_ID);
        $this->backend = $this->sqlBackend();
        $this->backend->expects($this->never())->method('addRecordGetId');

        // Unlinking writes no records, so metadata-edit standing is enough.
        $result = $this->manager([Permission::PERM_ZONE_META_EDIT_OTHERS])->updateZoneRecords(self::TTL, self::ZONE_ID, 0);

        $this->assertTrue($result->success);
        $this->assertSame([['id' => 101]], $this->rows('SELECT id FROM records'));
        $this->assertSame([['record_id' => 101]], $this->rows('SELECT record_id FROM records_zone_templ'));
        $this->assertSame([['zone_templ_id' => 0]], $this->rows('SELECT zone_templ_id FROM zones'));
        $this->assertSame([], $this->rows('SELECT id FROM zone_template_sync'));
        $this->assertSame([], $this->loggedDeletes);
    }

    public function testWithoutAZoneAddGrantTheOldRecordsGoButNoneAreWritten(): void
    {
        $this->seedZone(self::TEMPLATE_ID);
        $this->seedRecord(101, 'old.example', 'A', '192.0.2.1', linkedTo: self::TEMPLATE_ID);
        $this->seedTemplateRecords([['www.[ZONE]', 'A', '192.0.2.10', 300, 0]]);
        $this->backend = $this->sqlBackend();
        $this->backend->expects($this->never())->method('addRecordGetId');

        $result = $this->manager([Permission::PERM_ZONE_CONTENT_EDIT_OTHERS])->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame([], $this->rows('SELECT id FROM records'));
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ'));
        $this->assertSame([['zone_templ_id' => self::TEMPLATE_ID]], $this->rows('SELECT zone_templ_id FROM zones'));
        $this->assertSame([101], array_column($this->loggedDeletes, 'id'));
    }

    public function testApiBackendRemovesByEncodedIdAndSkipsTheSoa(): void
    {
        $zonesRowId = $this->seedZone(self::TEMPLATE_ID);
        $this->db->exec("INSERT INTO records_zone_templ_api (domain_id, record_id, zone_templ_id) VALUES (" . self::ZONE_ID . ", 'old.example./A/192.0.2.1', " . self::TEMPLATE_ID . ")");
        $this->db->exec("INSERT INTO records_zone_templ_api (domain_id, record_id, zone_templ_id) VALUES (" . self::ZONE_ID . ", 'old.example./TXT/gone', " . self::TEMPLATE_ID . ")");
        $this->seedTemplateRecords([
            ['[ZONE]', 'SOA', '[NS1] [HOSTMASTER] [SERIAL]', 0, 0],
            ['www.[ZONE]', 'A', '192.0.2.10', 0, 0],
        ]);

        $this->backend = $this->apiBackend();
        $this->backend->method('getRecordsByZoneId')->with(self::ZONE_ID)->willReturn([
            ['id' => 'old.example./A/192.0.2.1', 'name' => 'old.example', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 300, 'prio' => 0, 'disabled' => 0],
            ['id' => 'old.example./A/192.0.2.5', 'name' => 'old.example', 'type' => 'A', 'content' => '192.0.2.5', 'ttl' => 300, 'prio' => 0, 'disabled' => 0],
        ]);
        // The record removed out-of-band is not deleted again, only unmapped.
        $this->backend->expects($this->once())->method('deleteRecord')->with('old.example./A/192.0.2.1')->willReturn(true);
        $this->backend->method('recordExists')->willReturn(false);
        $this->backend->expects($this->once())->method('addRecordGetId')
            ->with(self::ZONE_ID, 'www.old.example', 'A', '192.0.2.10', self::TTL, 0)
            ->willReturnCallback(function (): string {
                $this->assertFalse($this->db->inTransaction(), 'API writes happen after the local rows are committed');
                return 'www.old.example./A/192.0.2.10';
            });

        $result = $this->manager()->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame(
            [['record_id' => 'www.old.example./A/192.0.2.10', 'zone_templ_id' => self::TEMPLATE_ID]],
            $this->rows('SELECT record_id, zone_templ_id FROM records_zone_templ_api')
        );
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ'));
        $this->assertSame([['zone_templ_id' => self::TEMPLATE_ID]], $this->rows('SELECT zone_templ_id FROM zones'));
        $this->assertSame(
            [['zone_id' => $zonesRowId, 'zone_templ_id' => self::TEMPLATE_ID, 'needs_sync' => 0]],
            $this->rows('SELECT zone_id, zone_templ_id, needs_sync FROM zone_template_sync')
        );
        $this->assertSame(['old.example./A/192.0.2.1'], array_column($this->loggedDeletes, 'id'));
        $this->assertSame(['www.old.example./A/192.0.2.10'], array_column($this->loggedCreates, 'id'));
        $this->assertFalse($this->db->inTransaction());
    }

    public function testApiBackendWithoutMappingsLeavesExistingRecordsAlone(): void
    {
        $this->seedZone(self::OLD_TEMPLATE_ID);
        $this->backend = $this->apiBackend();
        $this->backend->expects($this->never())->method('getRecordsByZoneId');
        $this->backend->expects($this->never())->method('deleteRecord');

        $this->assertTrue($this->manager()->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID)->success);
    }

    public function testBackendExceptionRollsBackAndReportsTheMessage(): void
    {
        $this->seedZone(self::OLD_TEMPLATE_ID);
        $this->seedRecord(101, 'old.example', 'A', '192.0.2.1', linkedTo: self::TEMPLATE_ID);
        $this->seedTemplateRecords([['www.[ZONE]', 'A', '192.0.2.10', 300, 0]]);
        $this->backend = $this->sqlBackend();
        $this->backend->method('recordExists')->willReturn(false);
        $this->backend->method('addRecordGetId')->willThrowException(new \RuntimeException('disk full'));

        $result = $this->manager()->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID);

        $this->assertFalse($result->success);
        $this->assertSame(500, $result->status);
        $this->assertSame('Failed to update zone records: disk full', $result->message);
        $this->assertSame([['id' => 101]], $this->rows('SELECT id FROM records'));
        $this->assertSame([['zone_templ_id' => self::OLD_TEMPLATE_ID]], $this->rows('SELECT zone_templ_id FROM zones'));
        $this->assertFalse($this->db->inTransaction());
    }

    public function testChangeLogFailureDoesNotFailTheApplication(): void
    {
        $this->seedZone(self::TEMPLATE_ID);
        $this->seedRecord(101, 'old.example', 'A', '192.0.2.1', linkedTo: self::TEMPLATE_ID);
        $this->changeLogger = $this->createMock(RecordChangeWriterInterface::class);
        $this->changeLogger->method('logRecordDelete')->willThrowException(new \RuntimeException('log table missing'));
        $this->backend = $this->sqlBackend();

        $result = $this->manager()->updateZoneRecords(self::TTL, self::ZONE_ID, self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame([], $this->rows('SELECT id FROM records'));
    }

    /**
     * @param string[] $callerPermissions
     */
    private function manager(array $callerPermissions = [Permission::PERM_ZONE_CONTENT_EDIT_OTHERS, Permission::PERM_ZONE_MASTER_ADD]): DomainManager
    {
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(self::CALLER_ID);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainType')->with(self::ZONE_ID)->willReturn($this->zoneType);
        $domains->method('getDomainNameById')->with(self::ZONE_ID)->willReturn($this->zoneName);

        $templates = new DbZoneTemplateRepository($this->db, $this->config, $this->backend);
        $applier = new ZoneTemplateApplier(
            $this->db,
            $this->backend,
            $this->soa,
            $domains,
            $templates,
            new DbTemplateRecordLinkRepository($this->db, $this->config, $this->backend),
            new ZoneTemplateSyncService($this->db, $this->config, $this->backend),
            new ZoneTemplatePlaceholders($this->config),
            $this->changeLogger,
            new NullLogger()
        );

        return new DomainManager(
            $this->db,
            $this->config,
            $domains,
            $this->createMock(RepositoryFactoryInterface::class),
            $this->backend,
            $this->buildPermissionService(permissionsByUser: [self::CALLER_ID => $callerPermissions]),
            $this->createMock(UserRepositoryInterface::class),
            $this->changeLogger,
            $applier,
            $templates,
            new ZoneTemplatePlaceholders($this->config),
            new NullLogger(),
            $userContext
        );
    }

    private function sqlBackend(): DnsBackendProviderInterface&MockObject
    {
        return $this->backendStub(false);
    }

    private function apiBackend(): DnsBackendProviderInterface&MockObject
    {
        return $this->backendStub(true);
    }

    private function backendStub(bool $isApi): DnsBackendProviderInterface&MockObject
    {
        $stub = $this->createMock(DnsBackendProviderInterface::class);
        $stub->method('isApiBackend')->willReturn($isApi);
        $stub->method('supportsLocalWriteTransaction')->willReturn(!$isApi);
        $stub->method('recordIdsAreNumeric')->willReturn(!$isApi);
        $stub->method('managesSoaRecord')->willReturn($isApi);
        $stub->method('allocatesZoneIdsLocally')->willReturn($isApi);
        return $stub;
    }

    /**
     * @return int The zones.id the sync rows key on
     */
    private function seedZone(int $templateId, string $name = 'old.example'): int
    {
        $this->zoneName = $name;
        $this->db->exec("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (" . self::ZONE_ID . ", " . self::CALLER_ID . ", $templateId)");
        return (int)$this->db->lastInsertId();
    }

    private function seedRecord(int $id, string $name, string $type, string $content, int $prio = 0, int $linkedTo = 0): void
    {
        $stmt = $this->db->prepare("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, ?, 300, ?)");
        $stmt->execute([$id, self::ZONE_ID, $name, $type, $content, $prio]);
        if ($linkedTo !== 0) {
            $stmt = $this->db->prepare("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (?, ?, ?)");
            $stmt->execute([self::ZONE_ID, $id, $linkedTo]);
        }
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
}
