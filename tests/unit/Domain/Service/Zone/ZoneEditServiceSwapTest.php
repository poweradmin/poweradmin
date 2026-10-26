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

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Application\Service\Record\RecordCommentService;
use Poweradmin\Application\Service\Record\RecordCommentSyncService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Enum\ZoneSaveOutcome;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Port\RecordWriteBackendInterface;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Port\ZoneRectifierInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\RepositoryFactoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Domain\Service\Zone\ZoneEditRow;
use Poweradmin\Domain\Service\Zone\ZoneEditService;
use Poweradmin\Domain\Service\Zone\ZoneEditSubmission;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use TestHelpers\FakeConfiguration;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\StubActor;

/**
 * A zone editor save that moves records into each other's place, run through the real
 * record manager: a swap or rotation is written, and any refusal undoes the whole save
 * so no row is left copying another.
 */
#[CoversClass(ZoneEditService::class)]
#[CoversClass(RecordManager::class)]
class ZoneEditServiceSwapTest extends PermissionServiceTestCase
{
    private const USER_ID = 7;
    private const ZONE_ID = 10;
    private const ZONE = 'example.test';

    /** @var array<int, array<string, mixed>> The zone's records by id, as the backend holds them */
    private array $rows = [];

    private ?TransactionInterface $transaction = null;

    #[Test]
    public function aSwapIsWritten(): void
    {
        $this->rows = [1 => $this->row(1, 'www', 'A', '192.0.2.1'), 2 => $this->row(2, 'www', 'A', '192.0.2.2')];

        $result = $this->save([$this->edit(1, 'www', 'A', '192.0.2.2'), $this->edit(2, 'www', 'A', '192.0.2.1')]);

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertSame(['192.0.2.2', '192.0.2.1'], $this->contents());
    }

    #[Test]
    public function aThreeWayRotationIsWritten(): void
    {
        $this->rows = [1 => $this->row(1, 'www', 'A', '192.0.2.1'), 2 => $this->row(2, 'www', 'A', '192.0.2.2'), 3 => $this->row(3, 'www', 'A', '192.0.2.3')];

        $result = $this->save([$this->edit(1, 'www', 'A', '192.0.2.2'), $this->edit(2, 'www', 'A', '192.0.2.3'), $this->edit(3, 'www', 'A', '192.0.2.1')]);

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertSame(['192.0.2.2', '192.0.2.3', '192.0.2.1'], $this->contents());
    }

    #[Test]
    public function aMoveOntoARowThatOnlyChangesItsTtlIsRefused(): void
    {
        $this->rows = [1 => $this->row(1, 'www', 'A', '192.0.2.1'), 2 => $this->row(2, 'www', 'A', '192.0.2.2')];

        $result = $this->save([$this->edit(1, 'www', 'A', '192.0.2.2'), $this->edit(2, 'www', 'A', '192.0.2.2', 7200)]);

        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
        $this->assertSame(['192.0.2.1', '192.0.2.2'], $this->contents());
    }

    #[Test]
    public function aRowThatOnlyAddsATrailingDotDoesNotMoveAway(): void
    {
        // Validation strips the dot, so the second row stays where it is
        $this->rows = [1 => $this->row(1, 'sub', 'NS', 'ns1.example.com'), 2 => $this->row(2, 'sub', 'NS', 'ns2.example.com')];

        $result = $this->save([$this->edit(1, 'sub', 'NS', 'ns2.example.com'), $this->edit(2, 'sub', 'NS', 'ns2.example.com.')]);

        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
        $this->assertContains('A record with this hostname, type, and content already exists.', $result->errors);
        $this->assertSame(['ns1.example.com', 'ns2.example.com'], $this->contents());
    }

    #[Test]
    public function aRowThatFailsValidationDoesNotMoveAway(): void
    {
        $this->rows = [1 => $this->row(1, 'www', 'A', '192.0.2.1'), 2 => $this->row(2, 'www', 'A', '192.0.2.5')];

        $result = $this->save([$this->edit(1, 'www', 'A', '999.0.0.1'), $this->edit(2, 'www', 'A', '192.0.2.1')]);

        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
        $this->assertSame(['192.0.2.1', '192.0.2.5'], $this->contents());
    }

    #[Test]
    public function aRefusalInASwapUndoesTheRowsAlreadyWritten(): void
    {
        $this->rows = [1 => $this->row(1, 'www', 'A', '192.0.2.1'), 2 => $this->row(2, 'www', 'A', '192.0.2.2'), 3 => $this->row(3, 'www', 'A', '192.0.2.3')];

        // Rows 1 and 2 swap; row 3 then moves onto row 1's old content, which row 2 now holds
        $result = $this->save([$this->edit(1, 'www', 'A', '192.0.2.2'), $this->edit(2, 'www', 'A', '192.0.2.1'), $this->edit(3, 'www', 'A', '192.0.2.1')]);

        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
        $this->assertSame(['192.0.2.1', '192.0.2.2', '192.0.2.3'], $this->contents());
        $this->assertContains('No record was saved: some rows take each other\'s place, so they are saved together or not at all.', $result->errors);
    }

    #[Test]
    public function aSwapLogsEachEditOnceItIsCommitted(): void
    {
        $this->rows = [1 => $this->row(1, 'www', 'A', '192.0.2.1'), 2 => $this->row(2, 'www', 'A', '192.0.2.2')];
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->exactly(2))->method('logRecordEdit')->willReturnCallback(function (): void {
            $this->assertFalse($this->transaction?->inTransaction(), 'An audit line is written only after the commit');
        });

        $this->save([$this->edit(1, 'www', 'A', '192.0.2.2'), $this->edit(2, 'www', 'A', '192.0.2.1')], $audit);
    }

    #[Test]
    public function aSwapThatIsRolledBackLogsNothing(): void
    {
        $this->rows = [1 => $this->row(1, 'www', 'A', '192.0.2.1'), 2 => $this->row(2, 'www', 'A', '192.0.2.2'), 3 => $this->row(3, 'www', 'A', '192.0.2.3')];
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->never())->method('logRecordEdit');

        $result = $this->save([$this->edit(1, 'www', 'A', '192.0.2.2'), $this->edit(2, 'www', 'A', '192.0.2.1'), $this->edit(3, 'www', 'A', '192.0.2.1')], $audit);

        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
    }

    #[Test]
    public function rowsThatDoNotOverlapStillSaveOneByOne(): void
    {
        $this->rows = [1 => $this->row(1, 'www', 'A', '192.0.2.1'), 2 => $this->row(2, 'mail', 'A', '192.0.2.2')];

        $result = $this->save([$this->edit(1, 'www', 'A', '999.0.0.1'), $this->edit(2, 'mail', 'A', '192.0.2.9')]);

        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
        $this->assertSame(['192.0.2.1', '192.0.2.9'], $this->contents());
    }

    /** @return list<string> */
    private function contents(): array
    {
        return array_values(array_map(static fn(array $r): string => (string)$r['content'], $this->rows));
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $label, string $type, string $content): array
    {
        return ['id' => (string)$id, 'domain_id' => self::ZONE_ID, 'name' => $label . '.' . self::ZONE, 'type' => $type, 'content' => $content, 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];
    }

    private function edit(int $id, string $label, string $type, string $content, int $ttl = 3600): ZoneEditRow
    {
        return new ZoneEditRow((string)$id, $label, $type, $content, $ttl, 0, false, null);
    }

    /** @param list<ZoneEditRow> $rows */
    private function save(array $rows, ?AuditService $audit = null): \Poweradmin\Domain\Service\Zone\ZoneSaveResult
    {
        $config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['ttl' => 3600, 'hostmaster' => 'hostmaster.example.test', 'bump_serial_on_unchanged_save' => true],
            'misc' => ['edit_conflict_resolution' => 'last_writer_wins', 'record_comments_sync' => false],
        ]);
        $transaction = $this->transaction = $this->transaction();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevelForZone')->willReturn('all');
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainType')->willReturn('MASTER');
        $domains->method('getDomainNameById')->willReturn(self::ZONE);
        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getRecordFromId')->willReturnCallback(fn(int|string $rid): ?array => $this->rows[(int)$rid] ?? null);
        $records->method('getRecordById')->willReturnCallback(fn(int|string $rid): ?array => $this->rows[(int)$rid] ?? null);
        $records->method('getRecordDetailsFromRecordId')->willReturnCallback(
            fn(int|string $rid): array => isset($this->rows[(int)$rid]) ? ['zid' => self::ZONE_ID] + $this->rows[(int)$rid] : []
        );
        $records->method('getRRSetRecords')->willReturnCallback(fn(int $zoneId, string $name, string $type): array => array_values(array_filter(
            $this->rows,
            static fn(array $r): bool => $r['name'] === $name && $r['type'] === $type
        )));
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->method('getSOARecord')->willReturn('ns1.example.test hostmaster.example.test 2024010101 10800 3600 604800 3600');

        $service = new ZoneEditService(
            $config,
            $permissions,
            $this->createMock(ZoneRepositoryInterface::class),
            $domains,
            $records,
            $this->recordManager($config, $transaction, $domains, $records, $soa),
            $soa,
            $this->createMock(RecordCommentService::class),
            $this->createMock(RecordCommentSyncService::class),
            $audit ?? $this->createMock(AuditService::class),
            false,
            false,
            $transaction
        );

        return $service->save(new ZoneEditSubmission(self::ZONE_ID, self::ZONE, self::USER_ID, 'alice', $rows, false, null, false, null));
    }

    /** A transaction over the in-memory rows: a rollback restores them as they were at begin */
    private function transaction(): TransactionInterface
    {
        $test = $this;

        return new class ($test) implements TransactionInterface {
            /** @var array<int, array<string, mixed>>|null */
            private ?array $snapshot = null;

            public function __construct(private readonly ZoneEditServiceSwapTest $test)
            {
            }

            public function begin(): void
            {
                $this->snapshot = $this->test->snapshot();
            }

            public function commit(): void
            {
                $this->snapshot = null;
            }

            public function rollBack(): void
            {
                $this->test->restore((array)$this->snapshot);
                $this->snapshot = null;
            }

            public function inTransaction(): bool
            {
                return $this->snapshot !== null;
            }
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function snapshot(): array
    {
        return $this->rows;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function restore(array $rows): void
    {
        $this->rows = $rows;
    }

    private function recordManager(
        FakeConfiguration $config,
        TransactionInterface $transaction,
        DomainRepositoryInterface $domains,
        RecordRepositoryInterface $records,
        SOARecordManagerInterface $soa
    ): RecordManager {
        $validation = $this->createMock(DnsRecordValidationServiceInterface::class);
        $validation->method('validateRecord')->willReturnCallback(
            static function (int|string $rid, int $zid, string $type, string $content, string $name, ?int $prio, ?int $ttl): ValidationResult {
                if ($content === '999.0.0.1') {
                    return ValidationResult::failure('Invalid IP address.');
                }

                return ValidationResult::success([
                    'content' => $type === 'NS' ? rtrim($content, '.') : $content,
                    'name' => $name,
                    'ttl' => $ttl,
                    'prio' => $prio,
                ]);
            }
        );
        $repositoryFactory = $this->createMock(RepositoryFactoryInterface::class);
        $repositoryFactory->method('createRecordRepository')->willReturn($records);

        $backend = $this->createMockForIntersectionOfInterfaces([RecordWriteBackendInterface::class, BackendCapabilitiesInterface::class]);
        $this->assertInstanceOf(RecordWriteBackendInterface::class, $backend);
        $this->assertInstanceOf(BackendCapabilitiesInterface::class, $backend);
        $backend->method('supportsLocalWriteTransaction')->willReturn(true);
        $backend->method('editRecord')->willReturnCallback(function (int|string $rid, string $name, string $type, string $content, int $ttl): bool {
            $this->rows[(int)$rid] = ['name' => $name, 'type' => $type, 'content' => $content, 'ttl' => $ttl] + $this->rows[(int)$rid];
            return true;
        });
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return new RecordManager(
            $transaction,
            $config,
            $validation,
            $soa,
            $domains,
            $repositoryFactory,
            fn() => $this->createMock(ZoneRectifierInterface::class),
            $backend,
            $this->buildPermissionService(permissionsByUser: [self::USER_ID => [Permission::PERM_ZONE_CONTENT_EDIT_OTHERS]]),
            $this->createMock(RecordChangeWriterInterface::class),
            new DbTemplateRecordLinkRepository($db, $config, $backend),
            new StubActor(self::USER_ID)
        );
    }
}
