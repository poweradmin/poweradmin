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
use Poweradmin\Application\Service\Backend\RepositoryFactory;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\ZoneTemplateApplier;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\Dns\DomainParsingService;
use Poweradmin\Domain\Service\Template\ZoneTemplatePlaceholders;
use Poweradmin\Infrastructure\Network\PdpPublicSuffixList;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateSyncRepository;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;
use TestHelpers\PermissionServiceTestCase;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use Poweradmin\Infrastructure\Repository\DbZoneAccountOwnerRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use TestHelpers\StubActor;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Domain\Service\Zone\ZoneAccountSyncService;

/**
 * addDomain() drives zone creation end to end: refusal before any write,
 * the native zones row, owner and group assignment, the default SOA or the
 * template records, the change-log entry, and compensating cleanup when a
 * backend write fails after the zone already exists.
 */
#[CoversClass(DomainManager::class)]
class DomainManagerAddDomainTest extends PermissionServiceTestCase
{
    private const CALLER_ID = 7;
    private const DOMAIN_ID = 77;
    private const TEMPLATE_ID = 9;

    private PDO $db;
    private FakeConfiguration $config;
    private DnsBackendProviderInterface&MockObject $backend;
    private RecordChangeWriterInterface&MockObject $changeLogger;

    /** @var list<array<string, mixed>> */
    private array $loggedZones = [];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)",
                "CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)",
                "CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id INTEGER, zone_templ_id INTEGER)",
                "CREATE TABLE records_zone_templ_api (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id TEXT, zone_templ_id INTEGER)",
                "CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER NOT NULL, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0, last_synced TEXT, template_last_modified TEXT)",
                "CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL, content TEXT NOT NULL, ttl INTEGER NOT NULL, prio INTEGER NOT NULL)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        $this->db->exec("INSERT INTO users (id, username) VALUES (" . self::CALLER_ID . ", 'caller')");

        $this->config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => [
                'ns1' => 'ns1.example', 'ns2' => 'ns2.example', 'ns3' => '', 'ns4' => '',
                'hostmaster' => 'hostmaster.example', 'ttl' => 3600,
                'soa_refresh' => 28800, 'soa_retry' => 7200, 'soa_expire' => 604800, 'soa_minimum' => 86400,
                'sync_zone_owner_to_account' => true,
            ],
        ]);

        $this->changeLogger = $this->createMock(RecordChangeWriterInterface::class);
        $this->changeLogger->method('logZoneCreate')->willReturnCallback(function (array $zone): void {
            $this->loggedZones[] = $zone;
        });
    }

    public function testUnknownZoneTypeIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->expects($this->never())->method('createZone');

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'BOGUS', '', 'none');

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::INVALID_INPUT, $result->refusal);
        $this->assertSame('Invalid or unexpected input given.', $result->message);
    }

    public function testCallerWithoutAnyAddGrantIsForbidden(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->expects($this->never())->method('createZone');

        $result = $this->manager([])->addDomain('new.example', self::CALLER_ID, 'MASTER', '', 'none');

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::FORBIDDEN, $result->refusal);
        $this->assertSame('You do not have the permission to add a master zone.', $result->message);
    }

    public function testMissingArgumentsAreRefusedBeforeTheBackendIsTouched(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->expects($this->never())->method('createZone');
        $manager = $this->manager();

        // A secondary needs a primary; a primary needs the template slot filled.
        foreach (
            [
                ['', 'MASTER', '', 'none'],
                ['new.example', 'SLAVE', '', 'none'],
                ['new.example', 'MASTER', '', ''],
            ] as [$domain, $type, $master, $template]
        ) {
            $result = $manager->addDomain($domain, self::CALLER_ID, $type, $master, $template);
            $this->assertFalse($result->success);
            $this->assertSame(Refusal::INVALID_INPUT, $result->refusal);
            $this->assertSame('Invalid argument(s) given to function addDomain', $result->message);
        }
    }

    public function testBackendRejectionLeavesNoNativeRowsAndDoesNotDeleteTheZone(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(false);
        $this->backend->expects($this->never())->method('deleteZone');

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', 'none', [4]);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result->refusal);
        $this->assertSame('Failed to create zone in DNS backend.', $result->message);
        $this->assertSame([], $this->rows('SELECT id FROM zones'));
        $this->assertSame([], $this->rows('SELECT id FROM zones_groups'));
    }

    public function testBackendExceptionOnCreateIsReportedWithItsMessage(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willThrowException(new \RuntimeException('api down'));
        $this->backend->expects($this->never())->method('deleteZone');

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', 'none');

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result->refusal);
        $this->assertSame('Failed to create zone: api down', $result->message);
    }

    public function testDefaultsSeedTheApexSoaAndLogTheZone(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->with('new.example', 'MASTER', '')->willReturn(self::DOMAIN_ID);
        $this->backend->expects($this->once())->method('setZoneSerialPolicy')
            ->with(self::DOMAIN_ID, 'new.example', ['soa_edit_api' => 'EPOCH'])->willReturn(true);
        $this->backend->expects($this->once())->method('addRecord')
            ->with(
                self::DOMAIN_ID,
                'new.example',
                'SOA',
                $this->matchesRegularExpression('/^ns1\.example hostmaster\.example \d{10} 28800 7200 604800 86400$/'),
                3600,
                0
            )
            ->willReturn(true);
        $this->backend->expects($this->once())->method('updateZoneAccount')
            ->with(self::DOMAIN_ID, 'caller')->willReturn(true);

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', 'none', [], 'EPOCH');

        $this->assertTrue($result->success);
        $this->assertSame(self::DOMAIN_ID, $result->zoneId);
        $this->assertSame(
            [['domain_id' => self::DOMAIN_ID, 'owner' => self::CALLER_ID, 'zone_templ_id' => 0]],
            $this->rows('SELECT domain_id, owner, zone_templ_id FROM zones')
        );
        $this->assertSame([], $this->rows('SELECT id FROM zone_template_sync'));
        $this->assertSame(
            [['id' => self::DOMAIN_ID, 'name' => 'new.example', 'type' => 'MASTER', 'owner' => self::CALLER_ID]],
            $this->loggedZones
        );
        $this->assertFalse($this->db->inTransaction());
    }

    public function testOwnerlessZoneWithGroupsSkipsTheAccountPushAndKeepsUniqueGroups(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        $this->backend->method('addRecord')->willReturn(true);
        $this->backend->expects($this->never())->method('updateZoneAccount');

        $result = $this->manager()->addDomain('new.example', null, 'NATIVE', '', 'none', [5, 5, 6]);

        $this->assertTrue($result->success);
        $this->assertSame([['owner' => null]], $this->rows('SELECT owner FROM zones'));
        $this->assertSame(
            [['group_id' => 5], ['group_id' => 6]],
            $this->rows('SELECT group_id FROM zones_groups ORDER BY group_id')
        );
        $this->assertSame(['owner' => null], array_intersect_key($this->loggedZones[0], ['owner' => true]));
    }

    public function testSecondaryZoneSkipsSoaAndTemplateAndLogsItsMaster(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->with('new.example', 'SLAVE', '192.0.2.1')->willReturn(self::DOMAIN_ID);
        $this->backend->expects($this->never())->method('setZoneSerialPolicy');
        $this->backend->expects($this->never())->method('addRecord');
        $this->backend->expects($this->never())->method('addRecordGetId');

        $result = $this->manager([Permission::PERM_ZONE_SLAVE_ADD])
            ->addDomain('new.example', self::CALLER_ID, 'SLAVE', ' 192.0.2.1 ', self::TEMPLATE_ID, [3]);

        $this->assertTrue($result->success);
        $this->assertSame(self::DOMAIN_ID, $result->zoneId);
        // The template id is stored, but no records are materialised for a transferred zone.
        $this->assertSame([['zone_templ_id' => self::TEMPLATE_ID]], $this->rows('SELECT zone_templ_id FROM zones'));
        $this->assertSame([['group_id' => 3]], $this->rows('SELECT group_id FROM zones_groups'));
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ'));
        $this->assertSame(
            [['id' => self::DOMAIN_ID, 'name' => 'new.example', 'type' => 'SLAVE', 'master' => '192.0.2.1', 'owner' => self::CALLER_ID]],
            $this->loggedZones
        );
    }

    public function testTemplateRecordsAreMaterialisedLinkedAndMarkedSynced(): void
    {
        $this->seedTemplateRecords([
            ['[ZONE]', 'NS', '[NS1]', 86400, 0],
            ['www.[ZONE]', 'A', '192.0.2.10', 0, 0],
            ['[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10],
        ]);
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        $this->backend->expects($this->never())->method('addRecord');
        $written = [];
        $this->backend->method('addRecordGetId')->willReturnCallback(function (int $domainId, string $name, string $type, string $content, int $ttl, int $prio) use (&$written) {
            $written[] = [$domainId, $name, $type, $content, $ttl, $prio];
            return 500 + count($written);
        });

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame([
            [self::DOMAIN_ID, 'new.example', 'NS', 'ns1.example', 86400, 0],
            [self::DOMAIN_ID, 'new.example', 'MX', 'mail.new.example', 3600, 10],
            [self::DOMAIN_ID, 'www.new.example', 'A', '192.0.2.10', 3600, 0],
        ], $written);
        $this->assertSame([['zone_templ_id' => self::TEMPLATE_ID]], $this->rows('SELECT zone_templ_id FROM zones'));
        $this->assertSame(
            [
                ['record_id' => 501, 'zone_templ_id' => self::TEMPLATE_ID],
                ['record_id' => 502, 'zone_templ_id' => self::TEMPLATE_ID],
                ['record_id' => 503, 'zone_templ_id' => self::TEMPLATE_ID],
            ],
            $this->rows('SELECT record_id, zone_templ_id FROM records_zone_templ ORDER BY record_id')
        );
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ_api'));
        $zonesRowId = (int)$this->db->query('SELECT id FROM zones')->fetchColumn();
        $this->assertSame(
            [['zone_id' => $zonesRowId, 'zone_templ_id' => self::TEMPLATE_ID, 'needs_sync' => 0]],
            $this->rows('SELECT zone_id, zone_templ_id, needs_sync FROM zone_template_sync')
        );
        $this->assertSame(
            [['id' => self::DOMAIN_ID, 'name' => 'new.example', 'type' => 'MASTER', 'template_id' => self::TEMPLATE_ID, 'owner' => self::CALLER_ID]],
            $this->loggedZones
        );
    }

    public function testBareTemplateNamesAreQualifiedWithTheZone(): void
    {
        $this->seedTemplateRecords([
            ['www', 'A', '192.0.2.10', 300, 0],
            ['@', 'TXT', '"v=spf1 -all"', 300, 0],
            ['ftp.new.example', 'CNAME', 'www.[ZONE]', 300, 0],
        ]);
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        $written = [];
        $this->backend->method('addRecordGetId')->willReturnCallback(function (int $domainId, string $name, string $type) use (&$written) {
            $written[] = [$name, $type];
            return 600 + count($written);
        });

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame([
            ['new.example', 'TXT'],
            ['ftp.new.example', 'CNAME'],
            ['www.new.example', 'A'],
        ], $written);
    }

    public function testTemplateWithGroupOwnersWritesExactLinkAndGroupRows(): void
    {
        $this->seedTemplateRecords([
            ['[ZONE]', 'NS', '[NS1]', 86400, 0],
            ['www.[ZONE]', 'A', '192.0.2.10', 300, 0],
        ]);
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        $this->backend->method('addRecordGetId')->willReturnOnConsecutiveCalls(501, 502);

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', self::TEMPLATE_ID, [4, 6, 4]);

        $this->assertTrue($result->success);
        $this->assertSame(
            [
                ['domain_id' => self::DOMAIN_ID, 'record_id' => 501, 'zone_templ_id' => self::TEMPLATE_ID],
                ['domain_id' => self::DOMAIN_ID, 'record_id' => 502, 'zone_templ_id' => self::TEMPLATE_ID],
            ],
            $this->rows('SELECT domain_id, record_id, zone_templ_id FROM records_zone_templ ORDER BY record_id')
        );
        $this->assertSame(
            [
                ['domain_id' => self::DOMAIN_ID, 'group_id' => 4],
                ['domain_id' => self::DOMAIN_ID, 'group_id' => 6],
            ],
            $this->rows('SELECT domain_id, group_id FROM zones_groups ORDER BY group_id')
        );
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ_api'));
        $this->assertFalse($this->db->inTransaction());
    }

    public function testNativeRowsAreWrittenInsideTheManagerTransaction(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        // The SOA write runs while the transaction is open: the zones and zones_groups
        // rows must already be visible on this connection, and uncommitted.
        $this->backend->method('addRecord')->willReturnCallback(function (): bool {
            $this->assertTrue($this->db->inTransaction());
            $this->assertSame([['domain_id' => self::DOMAIN_ID]], $this->rows('SELECT domain_id FROM zones'));
            $this->assertSame([['group_id' => 4]], $this->rows('SELECT group_id FROM zones_groups'));
            return false;
        });
        // deleteZone() runs after the rollback and before any compensating DELETE,
        // so empty tables here prove the rows rode on the manager's transaction.
        $this->backend->expects($this->once())->method('deleteZone')->willReturnCallback(function (): bool {
            $this->assertFalse($this->db->inTransaction());
            $this->assertSame([], $this->rows('SELECT id FROM zones'));
            $this->assertSame([], $this->rows('SELECT id FROM zones_groups'));
            return true;
        });

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', 'none', [4]);

        $this->assertFalse($result->success);
        $this->assertSame('Failed to create SOA record for zone.', $result->message);
    }

    public function testEmptyTemplateStillRegistersTheZoneForSync(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        $this->backend->expects($this->never())->method('addRecordGetId');

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', (string)self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->rows('SELECT id FROM zone_template_sync'));
        $this->assertSame(self::TEMPLATE_ID, $this->loggedZones[0]['template_id']);
    }

    public function testReverseZoneSkipsTemplateRecordTypesThatCannotLiveThere(): void
    {
        $this->seedTemplateRecords([
            ['[ZONE]', 'NS', '[NS1]', 86400, 0],
            ['[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10],
            ['[ZONE]', 'A', '192.0.2.1', 3600, 0],
        ]);
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        $types = [];
        $this->backend->method('addRecordGetId')->willReturnCallback(function (int $domainId, string $name, string $type) use (&$types) {
            $types[] = $type;
            return 600 + count($types);
        });

        $result = $this->manager()->addDomain('2.0.192.in-addr.arpa', self::CALLER_ID, 'MASTER', '', self::TEMPLATE_ID);

        $this->assertTrue($result->success);
        $this->assertSame(['NS'], $types);
        $this->assertCount(1, $this->rows('SELECT id FROM records_zone_templ'));
    }

    public function testApiBackendFillsTheExistingZoneRowAndLinksByStringId(): void
    {
        $this->seedTemplateRecords([['[ZONE]', 'NS', '[NS1]', 86400, 0]]);
        $this->backend = $this->apiBackend();
        // In API mode createZone() has already inserted the zones row with domain_id = id.
        $this->backend->method('createZone')->willReturnCallback(function (): int {
            $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (" . self::DOMAIN_ID . ", " . self::DOMAIN_ID . ", NULL, 0)");
            return self::DOMAIN_ID;
        });
        $this->backend->method('addRecordGetId')->willReturn('new.example./NS/new.example.');

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', self::TEMPLATE_ID, [4]);

        $this->assertTrue($result->success);
        $this->assertSame(
            [['id' => self::DOMAIN_ID, 'owner' => self::CALLER_ID, 'zone_templ_id' => self::TEMPLATE_ID]],
            $this->rows('SELECT id, owner, zone_templ_id FROM zones')
        );
        $this->assertSame([['group_id' => 4]], $this->rows('SELECT group_id FROM zones_groups'));
        $this->assertSame([['zone_id' => self::DOMAIN_ID]], $this->rows('SELECT zone_id FROM zone_template_sync'));
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ'));
        $this->assertSame(
            [['record_id' => 'new.example./NS/new.example.', 'zone_templ_id' => self::TEMPLATE_ID]],
            $this->rows('SELECT record_id, zone_templ_id FROM records_zone_templ_api')
        );
        $this->assertFalse($this->db->inTransaction());
    }

    public function testApiBackendSoaFailureRemovesTheCommittedMetadata(): void
    {
        $this->backend = $this->apiBackend();
        $this->backend->method('createZone')->willReturnCallback(function (): int {
            $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (" . self::DOMAIN_ID . ", " . self::DOMAIN_ID . ", NULL, 0)");
            return self::DOMAIN_ID;
        });
        $this->backend->method('addRecord')->willReturn(false);
        $this->backend->expects($this->once())->method('deleteZone')->with(self::DOMAIN_ID, 'new.example')->willReturn(true);

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', 'none', [4]);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result->refusal);
        $this->assertSame('Failed to create SOA record for zone.', $result->message);
        // The zones row was committed before the SOA write, so it is deleted rather than rolled back.
        $this->assertSame([], $this->rows('SELECT id FROM zones'));
        $this->assertSame([], $this->rows('SELECT id FROM zones_groups'));
        $this->assertSame([], $this->loggedZones);
        $this->assertFalse($this->db->inTransaction());
    }

    public function testApiBackendTemplateFailureEmptiesAllFourMetadataTables(): void
    {
        $this->seedTemplateRecords([
            ['[ZONE]', 'NS', '[NS1]', 86400, 0],
            ['www.[ZONE]', 'A', 'not-an-address', 300, 0],
        ]);
        $this->backend = $this->apiBackend();
        $this->backend->method('createZone')->willReturnCallback(function (): int {
            $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (" . self::DOMAIN_ID . ", " . self::DOMAIN_ID . ", NULL, 0)");
            return self::DOMAIN_ID;
        });
        $this->db->exec("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (" . self::DOMAIN_ID . ", 77, 1)");
        $this->backend->method('addRecordGetId')->willReturnCallback(function (int $domainId, string $name, string $type): ?string {
            // The first link is committed before the second write fails.
            if ($type === 'NS') {
                return 'new.example./NS/new.example.';
            }
            $this->assertSame([['record_id' => 'new.example./NS/new.example.']], $this->rows('SELECT record_id FROM records_zone_templ_api'));
            return null;
        });
        $this->backend->expects($this->once())->method('deleteZone')->with(self::DOMAIN_ID, 'new.example')->willReturn(true);

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', self::TEMPLATE_ID, [4]);

        $this->assertFalse($result->success);
        $this->assertSame('Failed to create A record for zone.', $result->message);
        $this->assertSame([], $this->rows('SELECT id FROM zones'));
        $this->assertSame([], $this->rows('SELECT id FROM zones_groups'));
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ'));
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ_api'));
        $this->assertFalse($this->db->inTransaction());
    }

    public function testSoaFailureRollsBackAndDeletesTheBackendZone(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        $this->backend->method('addRecord')->willReturn(false);
        $this->backend->expects($this->once())->method('deleteZone')->with(self::DOMAIN_ID, 'new.example')->willReturn(true);

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', 'none', [4]);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result->refusal);
        $this->assertSame('Failed to create SOA record for zone.', $result->message);
        $this->assertSame([], $this->rows('SELECT id FROM zones'));
        $this->assertSame([], $this->rows('SELECT id FROM zones_groups'));
        $this->assertSame([], $this->loggedZones);
        $this->assertFalse($this->db->inTransaction());
    }

    public function testTemplateRecordFailureNamesTheTypeAndCleansUp(): void
    {
        $this->seedTemplateRecords([
            ['[ZONE]', 'NS', '[NS1]', 86400, 0],
            ['www.[ZONE]', 'A', 'not-an-address', 300, 0],
        ]);
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        $this->backend->method('addRecordGetId')->willReturnCallback(fn(int $domainId, string $name, string $type) => $type === 'A' ? null : 501);
        $this->backend->expects($this->once())->method('deleteZone')->with(self::DOMAIN_ID, 'new.example')->willReturn(true);

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', self::TEMPLATE_ID, [4]);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result->refusal);
        $this->assertSame('Failed to create A record for zone.', $result->message);
        $this->assertSame([], $this->rows('SELECT id FROM zones'));
        $this->assertSame([], $this->rows('SELECT id FROM zones_groups'));
        $this->assertSame([], $this->rows('SELECT id FROM records_zone_templ'));
        $this->assertSame([], $this->rows('SELECT id FROM zone_template_sync'));
        $this->assertSame([], $this->loggedZones);
        $this->assertFalse($this->db->inTransaction());
    }

    public function testExceptionInsideTheTransactionRollsBackAndCleansUp(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        $this->backend->method('addRecord')->willThrowException(new \RuntimeException('disk full'));
        $this->backend->expects($this->once())->method('deleteZone')->with(self::DOMAIN_ID, 'new.example')->willReturn(true);

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', 'none', [4]);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result->refusal);
        $this->assertSame('Failed to create zone: disk full', $result->message);
        $this->assertSame([], $this->rows('SELECT id FROM zones'));
        $this->assertSame([], $this->rows('SELECT id FROM zones_groups'));
        $this->assertSame([], $this->loggedZones);
        $this->assertFalse($this->db->inTransaction());
    }

    public function testNonNumericTemplateIsRefusedAfterTheBackendZoneExists(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        $this->backend->expects($this->never())->method('addRecord');
        $this->backend->expects($this->never())->method('addRecordGetId');

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', 'bogus', [4]);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result->refusal);
        $this->assertSame('Invalid argument(s) given to function addDomain could not create zone', $result->message);
        $this->assertSame([], $this->rows('SELECT id FROM zones'));
        $this->assertSame([], $this->rows('SELECT id FROM zones_groups'));
        $this->assertSame([], $this->loggedZones);
        $this->assertFalse($this->db->inTransaction());
    }

    public function testChangeLogFailureDoesNotFailTheCreation(): void
    {
        $this->backend = $this->sqlBackend();
        $this->backend->method('createZone')->willReturn(self::DOMAIN_ID);
        $this->backend->method('addRecord')->willReturn(true);
        $this->changeLogger = $this->createMock(RecordChangeWriterInterface::class);
        $this->changeLogger->method('logZoneCreate')->willThrowException(new \RuntimeException('log table missing'));

        $result = $this->manager()->addDomain('new.example', self::CALLER_ID, 'MASTER', '', 'none');

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->rows('SELECT id FROM zones'));
    }

    /**
     * @param string[] $callerPermissions
     */
    private function manager(array $callerPermissions = [Permission::PERM_ZONE_MASTER_ADD, Permission::PERM_ZONE_SLAVE_ADD]): DomainManager
    {
        return new DomainManager(
            new PdoTransaction($this->db),
            $this->config,
            $this->createMock(DomainRepositoryInterface::class),
            new RepositoryFactory($this->db, $this->config, $this->backend),
            $this->backend,
            $this->buildPermissionService(permissionsByUser: [self::CALLER_ID => $callerPermissions]),
            $this->createMock(UserRepositoryInterface::class),
            $this->changeLogger,
            $this->createMock(ZoneTemplateApplier::class),
            new DbZoneTemplateRepository($this->db, $this->config, $this->backend),
            new ZoneTemplatePlaceholders($this->config, new DomainParsingService(new PdpPublicSuffixList())),
            new DbZoneTemplateSyncRepository($this->db, $this->config),
            new DbTemplateRecordLinkRepository($this->db, $this->config, $this->backend),
            new DbZoneGroupRepository($this->db, $this->config, $this->backend->isApiBackend()),
            new ZoneAccountSyncService(new DbZoneAccountOwnerRepository($this->db, $this->backend->allocatesZoneIdsLocally()), $this->config, $this->backend),
            new StubActor(self::CALLER_ID),
            new NullLogger()
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
