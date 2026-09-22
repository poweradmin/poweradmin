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

namespace Poweradmin\Tests\Unit\Application\Controller\Record;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Record\DeleteRecordsController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\RecordDeletionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Characterizes the multi-record delete page: what a confirmed deletion writes
 * per record, how the count is worded, where the page returns to, and the PTR
 * cleanup that follows an address record when the box is ticked.
 */
#[CoversClass(DeleteRecordsController::class)]
class DeleteRecordsControllerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;
    private const OTHER_ZONE_ID = 13;

    /** @var array<int, array<string, mixed>> Stored rows keyed by record id */
    private array $rows = [];

    /** @var array<int, int> Zone id per record id */
    private array $zoneOf = [];

    /** @var array<int, RecordWriteResult> Per-record delete outcome, ok when unset */
    private array $deleteResults = [];

    /** @var list<array{0: int|string, 1: bool}> deleteRecord() calls as [id, finalize] */
    private array $deleteCalls = [];

    /** @var list<int> */
    private array $finalizedZones = [];

    /** @var RecordRepositoryInterface&MockObject */
    private RecordRepositoryInterface $records;

    /** @var ReverseRecordCreator&MockObject */
    private ReverseRecordCreator $reverseCreator;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rows = [
            1 => ['id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0],
            2 => ['id' => 2, 'name' => 'mail.example.com', 'type' => 'MX', 'content' => 'mx.example.com', 'ttl' => 3600, 'prio' => 10],
        ];
        $this->zoneOf = [1 => self::ZONE_ID, 2 => self::ZONE_ID];

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevelForZone')->willReturn('all');
        $permissions->method('getViewPermissionLevel')->willReturn('all');

        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('getRecordFromId')->willReturnCallback(fn(int|string $id): ?array => $this->rows[(int)$id] ?? null);
        $this->records->method('getZoneIdFromRecordId')->willReturnCallback(fn(int|string $id): int => $this->zoneOf[(int)$id] ?? 0);
        $this->records->method('recidToDomid')->willReturnCallback(fn(int|string $id): int => $this->zoneOf[(int)$id] ?? 0);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getZoneInfoFromId')->willReturnCallback(
            fn(int $zoneId): array => in_array($zoneId, [self::ZONE_ID, self::OTHER_ZONE_ID], true) ? ['type' => 'MASTER'] : []
        );
        $domains->method('getDomainNameById')->willReturn('example.com');

        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('deleteRecord')->willReturnCallback(function (int|string $id, bool $finalize = true): RecordWriteResult {
            $this->deleteCalls[] = [$id, $finalize];
            return $this->deleteResults[(int)$id] ?? RecordWriteResult::ok();
        });
        $recordManager->method('finalizeZone')->willReturnCallback(function (int $zoneId): void {
            $this->finalizedZones[] = $zoneId;
        });

        $this->reverseCreator = $this->createMock(ReverseRecordCreator::class);
        $this->audit = $this->createMock(AuditService::class);

        $changeLog = $this->createMock(RecordChangeLogger::class);
        $changeLog->method('changeCommentRequired')->willReturn(false);
        $changeLog->method('withChangeset')->willReturnCallback(static fn(?int $zoneId, ?string $comment, callable $work): mixed => $work());

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('recordRepository')->willReturn($this->records);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('recordManager')->willReturn($recordManager);
        $this->factory->method('reverseRecordCreator')->willReturn($this->reverseCreator);
        $this->factory->method('auditService')->willReturn($this->audit);
        $this->factory->method('recordChangeLog')->willReturn($changeLog);
        $this->factory->method('recordDeletionService')->willReturnCallback(fn(): RecordDeletionService => new RecordDeletionService(
            $this->records,
            $recordManager,
            $this->reverseCreator,
            $this->audit,
            (bool)ConfigurationManager::getInstance()->get('interface', 'add_reverse_record', false)
        ));
    }

    /** @param array<string, array<string, mixed>> $config */
    private function makeController(array $config = []): TestableDeleteRecordsController
    {
        return new TestableDeleteRecordsController(array_merge($_GET, $_POST), $this->environment($this->configure($config)));
    }

    private function haltOf(TestableDeleteRecordsController $controller): RequestHalted
    {
        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }

    /**
     * @param list<int|string> $ids
     * @param array<string, mixed> $extra
     */
    private function confirm(array $ids, array $extra = []): void
    {
        $this->post(['record_id' => $ids, 'confirm' => '1'] + $extra);
    }

    public function testAnEmptySelectionIsSentBackToTheSearchPage(): void
    {
        $this->post(['confirm' => '1']);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame('/search', $halt->target);
        $this->assertSame([['error', 'No records selected for deletion.']], $this->messagesFor('search'));
    }

    public function testWithoutConfirmationTheSelectionIsOnlyShown(): void
    {
        $this->post(['record_id' => [1, 2]]);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([], $this->deleteCalls);
        $this->assertSame('delete_records.html', $controller->rendered[0][0]);
        $this->assertSame(2, $controller->rendered[0][1]['total_records']);
    }

    public function testEveryRecordIsDeletedWithoutFinalizingAndEachZoneIsFinalizedOnce(): void
    {
        $this->zoneOf[2] = self::OTHER_ZONE_ID;
        $this->confirm([1, 2]);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame([[1, false], [2, false]], $this->deleteCalls);
        $this->assertSame([self::ZONE_ID, self::OTHER_ZONE_ID], $this->finalizedZones);
        $this->assertSame('/search', $halt->target);
        $this->assertSame(
            [['success', '2 records have been deleted successfully. Any corresponding PTR records were also removed.']],
            $this->messagesFor('search')
        );
    }

    public function testASingleDeletionIsWordedInTheSingular(): void
    {
        $this->confirm([1]);

        $this->haltOf($this->makeController());

        $this->assertSame(
            [['success', 'The record has been deleted successfully. Any corresponding PTR records were also removed.']],
            $this->messagesFor('search')
        );
    }

    public function testEachDeletedRecordIsAudited(): void
    {
        $this->audit->expects($this->exactly(2))->method('logRecordDelete')
            ->willReturnCallback(function (int $zoneId, string $type, string $name, string $content, int|string $ttl, int|string|null $prio): void {
                $this->assertSame(self::ZONE_ID, $zoneId);
                $this->assertContains($type, ['A', 'MX']);
            });
        $this->confirm([1, 2]);

        $this->haltOf($this->makeController());
    }

    public function testAMissingRowIsSkippedAndTheRestStillGoThrough(): void
    {
        $this->confirm([1, 99]);

        $this->haltOf($this->makeController());

        $this->assertSame([[1, false]], $this->deleteCalls);
        $this->assertSame(
            [['success', 'The record has been deleted successfully. Any corresponding PTR records were also removed.']],
            $this->messagesFor('search')
        );
    }

    public function testARefusedRowFlashesItsReasonAndDoesNotCount(): void
    {
        $this->deleteResults[2] = RecordWriteResult::forbidden('SOA records cannot be deleted');
        $this->confirm([1, 2]);

        $this->haltOf($this->makeController());

        $this->assertSame([['error', 'SOA records cannot be deleted']], $this->messagesFor('system'));
        $this->assertSame(
            [['success', 'The record has been deleted successfully. Any corresponding PTR records were also removed.']],
            $this->messagesFor('search')
        );
        $this->assertSame([self::ZONE_ID], $this->finalizedZones);
    }

    public function testWhenNothingCouldBeDeletedThePageReportsAnError(): void
    {
        $this->deleteResults[1] = RecordWriteResult::forbidden('no');
        $this->confirm([1]);

        $this->haltOf($this->makeController());

        $this->assertSame([], $this->finalizedZones);
        $this->assertSame([['error', 'No records could be deleted. Please check permissions.']], $this->messagesFor('search'));
    }

    public function testASubmissionFromTheZoneEditorReturnsThere(): void
    {
        $this->confirm([1], ['zone_id' => (string)self::ZONE_ID]);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame('/zones/12/edit', $halt->target);
        $this->assertNotSame([], $this->messagesFor('edit'));
        $this->assertSame([], $this->messagesFor('search'));
    }

    public function testAnUnknownZoneIdFallsBackToTheSearchPage(): void
    {
        $this->confirm([1], ['zone_id' => '999']);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame('/search', $halt->target);
    }

    public function testThePtrOfAnAddressRecordIsRemovedWhenAskedAndReverseHandlingIsOn(): void
    {
        $this->reverseCreator->expects($this->once())->method('deleteReverseRecord')
            ->with('A', '192.0.2.1', 'www.example.com')
            ->willReturn(true);
        $this->confirm([1, 2], ['delete_ptr' => '1']);

        $this->haltOf($this->makeController(['interface' => ['add_reverse_record' => true]]));
    }

    public function testThePtrStaysWhenTheBoxIsNotTicked(): void
    {
        $this->reverseCreator->expects($this->never())->method('deleteReverseRecord');
        $this->confirm([1]);

        $this->haltOf($this->makeController(['interface' => ['add_reverse_record' => true]]));
    }

    public function testThePtrStaysWhenReverseHandlingIsOff(): void
    {
        $this->reverseCreator->expects($this->never())->method('deleteReverseRecord');
        $this->confirm([1], ['delete_ptr' => '1']);

        $this->haltOf($this->makeController(['interface' => ['add_reverse_record' => false]]));
    }

    public function testARefusedRowNeverHasItsPtrRemoved(): void
    {
        $this->deleteResults[1] = RecordWriteResult::forbidden('no');
        $this->reverseCreator->expects($this->never())->method('deleteReverseRecord');
        $this->confirm([1], ['delete_ptr' => '1']);

        $this->haltOf($this->makeController(['interface' => ['add_reverse_record' => true]]));
    }
}
