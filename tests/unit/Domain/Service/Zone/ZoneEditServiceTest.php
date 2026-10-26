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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Record\RecordCommentService;
use Poweradmin\Application\Service\Record\RecordCommentSyncService;
use Poweradmin\Application\Service\Zone\ZoneSaveMessages;
use Poweradmin\Domain\Enum\ZoneSaveOutcome;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneEditRow;
use Poweradmin\Domain\Service\Zone\ZoneEditService;
use Poweradmin\Domain\Service\Zone\ZoneEditSubmission;
use Poweradmin\Domain\ValueObject\RecordIdentifier;
use TestHelpers\FakeConfiguration;

/**
 * The zone editor save: the edit gate and read-only refusal come first, a stale
 * form is refused only under the strict strategy, rows are written only when
 * they differ, and the serial bump follows the outcome and dns.bump_serial_on_unchanged_save.
 */
class ZoneEditServiceTest extends TestCase
{
    private const ZONE_ID = 42;
    private const USER_ID = 7;
    private const SOA = 'ns1.example.com hostmaster.example.com 2024010101 10800 3600 604800 3600';
    private const SOA_BUMPED = 'ns1.example.com hostmaster.example.com 2024010102 10800 3600 604800 3600';

    /** What the zone's SOA reads as right now; a test moves it to stand in for a backend bump. */
    private string $currentSoa = self::SOA;

    private PermissionService&MockObject $permissions;
    private ZoneRepositoryInterface&MockObject $zones;
    private DomainRepositoryInterface&MockObject $domains;
    private RecordRepositoryInterface&MockObject $records;
    private RecordManagerInterface&MockObject $recordManager;
    private SOARecordManagerInterface&MockObject $soa;
    private RecordCommentService&MockObject $comments;
    private RecordCommentSyncService&MockObject $commentSync;

    protected function setUp(): void
    {
        $this->permissions = $this->createMock(PermissionService::class);
        $this->permissions->method('getEditPermissionLevelForZone')->willReturn('all');
        $this->permissions->method('userOwnsZone')->willReturn(false);
        $this->zones = $this->createMock(ZoneRepositoryInterface::class);
        $this->zones->method('getZoneComment')->willReturn('');
        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('getDomainType')->willReturn('MASTER');
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->recordManager = $this->createMock(RecordManagerInterface::class);
        $this->soa = $this->createMock(SOARecordManagerInterface::class);
        $this->currentSoa = self::SOA;
        $this->soa->method('getSOARecord')->willReturnCallback(fn(): string => $this->currentSoa);
        $this->comments = $this->createMock(RecordCommentService::class);
        $this->commentSync = $this->createMock(RecordCommentSyncService::class);
    }

    public static function deniedLevels(): array
    {
        return [
            'no edit permission' => ['none', true],
            'own but not owner' => ['own', false],
            'own_as_client but not owner' => ['own_as_client', false],
        ];
    }

    #[DataProvider('deniedLevels')]
    public function testSaveIsRefusedWithoutEditPermission(string $level, bool $owner): void
    {
        $this->permissions = $this->createMock(PermissionService::class);
        $this->permissions->method('getEditPermissionLevelForZone')->willReturn($level);
        $this->permissions->method('userOwnsZone')->with(self::USER_ID, self::ZONE_ID)->willReturn($owner);
        $this->zones->expects($this->never())->method('updateZoneComment');
        $this->recordManager->expects($this->never())->method('finalizeZone');

        $result = $this->makeService()->save($this->submission(zoneComment: 'x'));

        $this->assertSame(ZoneSaveOutcome::FORBIDDEN, $result->outcome);
        $this->assertSame('error', ZoneSaveMessages::forResult($result)[0]);
    }

    public function testReadOnlyZonesTakeNoSave(): void
    {
        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('getDomainType')->willReturn('SLAVE');
        $this->zones = $this->createMock(ZoneRepositoryInterface::class);
        $this->zones->expects($this->never())->method('updateZoneComment');

        $result = $this->makeService()->save($this->submission(zoneComment: 'x'));

        $this->assertSame(ZoneSaveOutcome::READ_ONLY, $result->outcome);
        $this->assertStringContainsString('read-only', ZoneSaveMessages::forResult($result)[1]);
    }

    public function testUnchangedSaveBumpsSerialByDefault(): void
    {
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID, true, false)
            ->willReturnCallback(fn() => $this->currentSoa = self::SOA_BUMPED);

        $result = $this->makeService()->save($this->submission());

        $this->assertSame(ZoneSaveOutcome::NO_CHANGES, $result->outcome);
        $this->assertTrue($result->serialBumped);
        $this->assertStringContainsString('SOA serial was incremented', ZoneSaveMessages::forResult($result)[1]);
    }

    public function testUnchangedSaveLeavesSerialAloneWhenDisabled(): void
    {
        $this->recordManager->expects($this->never())->method('finalizeZone');

        $result = $this->makeService(['dns' => ['bump_serial_on_unchanged_save' => false]])->save($this->submission());

        $this->assertSame(ZoneSaveOutcome::NO_CHANGES, $result->outcome);
        $this->assertFalse($result->serialBumped);
        $this->assertSame(['info', 'Zone saved successfully. No record changes were made.'], ZoneSaveMessages::forResult($result));
    }

    public function testChangedZoneCommentCountsAsAChangeAndBumpsEvenWhenDisabled(): void
    {
        $this->zones->expects($this->once())->method('updateZoneComment')->with(self::ZONE_ID, 'new comment')->willReturn(true);
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID, true, false)
            ->willReturnCallback(fn() => $this->currentSoa = self::SOA_BUMPED);

        $result = $this->makeService(['dns' => ['bump_serial_on_unchanged_save' => false]])
            ->save($this->submission(zoneComment: 'new comment'));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertTrue($result->serialBumped);
    }

    public function testAnAbsentZoneCommentKeepsTheStoredOne(): void
    {
        $this->zones = $this->createMock(ZoneRepositoryInterface::class);
        $this->zones->method('getZoneComment')->willReturn('from B');
        $this->zones->expects($this->never())->method('updateZoneComment');

        $result = $this->makeService()->save($this->submission());

        $this->assertSame(ZoneSaveOutcome::NO_CHANGES, $result->outcome);
    }

    public function testADeliberatelyEmptiedZoneCommentIsStillWritten(): void
    {
        $this->zones = $this->createMock(ZoneRepositoryInterface::class);
        $this->zones->method('getZoneComment')->willReturn('old');
        $this->zones->expects($this->once())->method('updateZoneComment')->with(self::ZONE_ID, '')->willReturn(true);

        $result = $this->makeService()->save($this->submission(zoneComment: ''));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
    }

    public function testStaleFormIsRefusedOnlyUnderTheStrictStrategy(): void
    {
        $this->recordManager->expects($this->never())->method('editRecord');
        $this->recordManager->expects($this->never())->method('finalizeZone');
        $rows = [$this->row('5', 'www', '192.0.2.9')];

        $result = $this->makeService(['misc' => ['edit_conflict_resolution' => 'only_latest_version']])
            ->save($this->submission($rows, serial: '2023010101', changedRowsOnly: true, zoneComment: 'typed'));

        $this->assertSame(ZoneSaveOutcome::SERIAL_CONFLICT, $result->outcome);
        $this->assertSame($rows, $result->rejectedRecords);
        $this->assertSame('typed', $result->rejectedZoneComment);
        $this->assertSame('warning', ZoneSaveMessages::forResult($result)[0]);
    }

    public function testStaleFormWithoutAnEditedCommentCarriesNoCommentBack(): void
    {
        $result = $this->makeService(['misc' => ['edit_conflict_resolution' => 'only_latest_version']])
            ->save($this->submission([$this->row('5', 'www', '192.0.2.9')], serial: '2023010101', changedRowsOnly: true));

        $this->assertSame(ZoneSaveOutcome::SERIAL_CONFLICT, $result->outcome);
        $this->assertNull($result->rejectedZoneComment);
    }

    public function testAnApiRecordChangedByAnotherWriterIsRefusedAndTheOtherRowsAreStillWritten(): void
    {
        $staleId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.0.2.1', 0);
        $mailId = RecordIdentifier::encode('example.com', 'mail.example.com', 'A', '192.0.2.5', 0);
        $this->records->method('getRecordById')->willReturnCallback(fn($rid) => $rid === $mailId ? $this->stored($mailId, 'mail', '192.0.2.5') : null);
        $this->records->method('getRecordFromId')->willReturn($this->stored($mailId, 'mail', '192.0.2.5'));
        $written = [];
        $this->recordManager->expects($this->once())->method('editRecord')
            ->willReturnCallback(function (array $record) use (&$written): RecordWriteResult {
                $written[] = $record['rid'];
                return RecordWriteResult::ok();
            });

        $result = $this->makeService()->save($this->submission([
            $this->row($staleId, 'www', '192.0.2.9'),
            $this->row($mailId, 'mail', '192.0.2.6'),
        ]));

        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
        $this->assertSame([$mailId], $written);
        $this->assertSame(['Someone else changed this zone after you opened it. Review the records and save again.'], $result->errors);
        $this->assertCount(1, $result->rejectedRecords);
        $this->assertSame($staleId, $result->rejectedRecords[0]->rid);
        $this->assertSame('192.0.2.9', $result->rejectedRecords[0]->content);
    }

    public function testAStaleApiRecordIsNotWrittenOverASiblingOfItsRRset(): void
    {
        $id1 = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.0.2.1', 0);
        $id2 = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.0.2.2', 0);
        $this->records->method('getRecordById')->willReturnCallback(fn($rid) => $rid === $id1 ? $this->stored($id1, 'www', '192.0.2.1') : null);
        $this->recordManager->expects($this->never())->method('editRecord');

        $result = $this->makeService()->save($this->submission([$this->row($id2, 'www', '192.0.2.9')], changedRowsOnly: true));

        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
        $this->assertCount(1, $result->rejectedRecords);
        $this->assertSame($id2, $result->rejectedRecords[0]->rid);
    }

    public function testARecordWrittenEarlierInTheSaveIsNotOverwrittenByALaterStaleRow(): void
    {
        $id1 = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.0.2.1', 0);
        $id2 = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.0.2.2', 0);
        $this->records->method('getRecordById')->willReturnCallback(fn($rid) => $rid === $id1 ? $this->stored($id1, 'www', '192.0.2.1') : null);
        $this->records->method('getRecordFromId')->willReturn($this->stored($id1, 'www', '192.0.2.1'));
        $written = [];
        $this->recordManager->method('editRecord')
            ->willReturnCallback(function (array $record) use (&$written): RecordWriteResult {
                $written[] = [$record['rid'], $record['content']];
                return RecordWriteResult::ok();
            });

        $result = $this->makeService()->save($this->submission([
            $this->row($id1, 'www', '192.0.2.8'),
            $this->row($id2, 'www', '192.0.2.9'),
        ]));

        $this->assertSame([[$id1, '192.0.2.8']], $written);
        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
        $this->assertSame($id2, $result->rejectedRecords[0]->rid);
    }

    public function testAStaleSoaRowKeepsTheCurrentSerialInsteadOfWritingTheOldOneBack(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->storedSoa('6', 'ns1.example.com hostmaster.example.com 2024010101 10800 3600 604800 3600'));
        $written = null;
        $this->recordManager->expects($this->once())->method('editRecord')
            ->willReturnCallback(function (array $record) use (&$written): RecordWriteResult {
                $written = $record;
                return RecordWriteResult::ok();
            });
        $row = new ZoneEditRow('6', 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2023010101 7200 3600 604800 3600', 3600, 0, false, null);

        $result = $this->makeService()->save($this->submission([$row], serial: '2023010101'));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertSame('ns1.example.com hostmaster.example.com 2024010101 7200 3600 604800 3600', $written['content']);
    }

    public function testARefusedStaleFormHandsBackItsSoaRowWithTheCurrentSerial(): void
    {
        $this->recordManager->expects($this->never())->method('editRecord');
        $row = new ZoneEditRow('6', 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2023010101 7200 3600 604800 3600', 3600, 0, false, null);

        $result = $this->makeService(['misc' => ['edit_conflict_resolution' => 'only_latest_version']])
            ->save($this->submission([$row], serial: '2023010101', changedRowsOnly: true));

        $this->assertSame(ZoneSaveOutcome::SERIAL_CONFLICT, $result->outcome);
        $this->assertSame('ns1.example.com hostmaster.example.com 2024010101 7200 3600 604800 3600', $result->rejectedRecords[0]->content);
    }

    public function testAnUntouchedApiSoaRowWhoseIdTheBumpOutdatedIsNotRefused(): void
    {
        [$oldId, $liveId] = $this->moveApiSoa();
        $this->recordManager->expects($this->never())->method('editRecord');
        $row = new ZoneEditRow($oldId, 'example.com', 'SOA', self::SOA, 3600, 0, false, null);

        $result = $this->makeService()->save($this->submission([$row], serial: '2024010101'));

        $this->assertSame(ZoneSaveOutcome::NO_CHANGES, $result->outcome);
        $this->assertSame([], $result->errors);
        $this->assertSame([], $result->rejectedRecords);
    }

    public function testAnUntouchedApiSoaRowDoesNotStopAnotherRowFromBeingWritten(): void
    {
        $wwwId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.0.2.1', 0);
        [$oldId] = $this->moveApiSoa([$wwwId => $this->stored($wwwId, 'www', '192.0.2.1')]);
        $this->recordManager->expects($this->once())->method('editRecord')->willReturn(RecordWriteResult::ok());
        $soaRow = new ZoneEditRow($oldId, 'example.com', 'SOA', self::SOA, 3600, 0, false, null);

        $result = $this->makeService()->save($this->submission([$soaRow, $this->row($wwwId, 'www', '192.0.2.9')], serial: '2024010101'));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertSame([], $result->errors);
    }

    public function testAnEditedApiSoaRowWhoseIdTheBumpOutdatedIsWrittenToTheLiveRecordWithTheCurrentSerial(): void
    {
        [$oldId, $liveId] = $this->moveApiSoa();
        $written = null;
        $this->recordManager->expects($this->once())->method('editRecord')
            ->willReturnCallback(function (array $record) use (&$written): RecordWriteResult {
                $written = $record;
                return RecordWriteResult::ok();
            });
        $row = new ZoneEditRow($oldId, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2024010101 7200 3600 604800 3600', 3600, 0, false, null);

        $result = $this->makeService()->save($this->submission([$row], serial: '2024010101'));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertSame([], $result->errors);
        $this->assertSame($liveId, $written['rid']);
        $this->assertSame('ns1.example.com hostmaster.example.com 2024010102 7200 3600 604800 3600', $written['content']);
    }

    public function testARefusedStaleApiFormHandsBackItsSoaRowWithTheLiveIdAndCurrentSerial(): void
    {
        [$oldId, $liveId] = $this->moveApiSoa();
        $this->recordManager->expects($this->never())->method('editRecord');
        $row = new ZoneEditRow($oldId, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2024010101 7200 3600 604800 3600', 3600, 0, false, null);

        $result = $this->makeService(['misc' => ['edit_conflict_resolution' => 'only_latest_version']])
            ->save($this->submission([$row], serial: '2024010101', changedRowsOnly: true));

        $this->assertSame(ZoneSaveOutcome::SERIAL_CONFLICT, $result->outcome);
        $this->assertSame($liveId, $result->rejectedRecords[0]->rid);
        $this->assertSame('ns1.example.com hostmaster.example.com 2024010102 7200 3600 604800 3600', $result->rejectedRecords[0]->content);
    }

    public function testAnApiSoaRowThatCannotBeResolvedIsStillRefused(): void
    {
        $oldId = RecordIdentifier::encode('example.com', 'example.com', 'SOA', self::SOA, 0);
        $this->records->method('getRecordById')->willReturn(null);
        $this->records->method('getRecordsByName')->willReturn([]);
        $this->recordManager->expects($this->never())->method('editRecord');
        $row = new ZoneEditRow($oldId, 'example.com', 'SOA', self::SOA, 3600, 0, false, null);

        $result = $this->makeService()->save($this->submission([$row], serial: '2024010101'));

        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
        $this->assertSame($oldId, $result->rejectedRecords[0]->rid);
    }

    /**
     * Another write moved the API zone's serial: the form's SOA id (built from the old SOA)
     * no longer resolves, while the zone's apex SOA RRset holds the bumped record.
     *
     * @param array<string, array<string, mixed>> $others Other stored records by id, which still resolve
     * @return array{0: string, 1: string} The form's old SOA id and the live one
     */
    private function moveApiSoa(array $others = []): array
    {
        $oldId = RecordIdentifier::encode('example.com', 'example.com', 'SOA', self::SOA, 0);
        $liveId = RecordIdentifier::encode('example.com', 'example.com', 'SOA', self::SOA_BUMPED, 0);
        $this->currentSoa = self::SOA_BUMPED;
        $live = $this->storedSoa($liveId, self::SOA_BUMPED);
        $this->records->method('getRecordById')->willReturnCallback(fn($rid) => ($others + [$liveId => $live])[$rid] ?? null);
        $this->records->method('getRecordFromId')->willReturnCallback(fn($rid) => ($others + [$liveId => $live])[$rid] ?? null);
        $this->records->expects($this->atLeastOnce())->method('getRecordsByName')->with(self::ZONE_ID, 'example.com', 'SOA')->willReturn([$live]);

        return [$oldId, $liveId];
    }

    public function testAnApiRowReKeyedByAnEarlierWriteInTheSaveIsResolvedWhenWritten(): void
    {
        $nsId = RecordIdentifier::encode('example.com', 'example.com', 'NS', 'ns1.example.com', 0);
        $oldSoaId = RecordIdentifier::encode('example.com', 'example.com', 'SOA', self::SOA, 0);
        $liveSoaId = RecordIdentifier::encode('example.com', 'example.com', 'SOA', self::SOA_BUMPED, 0);
        $wwwId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.0.2.1', 0);
        $before = [
            $nsId => ['type' => 'NS', 'name' => 'example.com', 'content' => 'ns1.example.com'] + $this->stored($nsId, 'www', ''),
            $oldSoaId => $this->storedSoa($oldSoaId, self::SOA),
            $wwwId => $this->stored($wwwId, 'www', '192.0.2.1'),
        ];
        // The first write bumps the serial through SOA-EDIT-API and another writer re-keys www meanwhile
        $after = [$liveSoaId => $this->storedSoa($liveSoaId, self::SOA_BUMPED)];
        $lookup = function ($rid) use ($before, $after): ?array {
            return ($this->currentSoa === self::SOA_BUMPED ? $after : $before)[$rid] ?? null;
        };
        $this->records->method('getRecordById')->willReturnCallback($lookup);
        $this->records->method('getRecordFromId')->willReturnCallback($lookup);
        $this->records->method('getRecordsByName')->willReturnCallback(function () use ($lookup, $liveSoaId, $oldSoaId): array {
            return [$lookup($this->currentSoa === self::SOA_BUMPED ? $liveSoaId : $oldSoaId)];
        });
        $editedIds = [];
        $writtenContent = [];
        $this->recordManager->method('editRecord')->willReturnCallback(function (array $record) use (&$editedIds, &$writtenContent, $lookup): RecordWriteResult {
            if ($lookup($record['rid']) === null) {
                return RecordWriteResult::notFound('Record not found.');
            }
            $editedIds[] = $record['rid'];
            $writtenContent[$record['rid']] = $record['content'];
            $this->currentSoa = self::SOA_BUMPED;
            return RecordWriteResult::ok();
        });
        $nsRow = new ZoneEditRow($nsId, 'example.com', 'NS', 'ns2.example.com', 3600, 0, false, null);
        $soaRow = new ZoneEditRow($oldSoaId, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2024010101 7200 3600 604800 3600', 3600, 0, false, null);
        $wwwRow = $this->row($wwwId, 'www', '192.0.2.9');

        $result = $this->makeService()->save($this->submission([$nsRow, $soaRow, $wwwRow], serial: '2024010101'));

        $this->assertSame([$nsId, $liveSoaId], $editedIds);
        // The SOA edit lands on the live record and keeps the serial the earlier write bumped
        $this->assertSame('ns1.example.com hostmaster.example.com 2024010102 7200 3600 604800 3600', $writtenContent[$liveSoaId]);
        $this->assertSame(['Someone else changed this zone after you opened it. Review the records and save again.'], $result->errors);
        $this->assertSame([$wwwRow], $result->rejectedRecords);
    }

    public function testAStaleSoaRowDifferingOnlyInTheSerialWritesNothing(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->storedSoa('6', self::SOA));
        $this->recordManager->expects($this->never())->method('editRecord');
        $row = new ZoneEditRow('6', 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2023010101 10800 3600 604800 3600', 3600, 0, false, null);

        $result = $this->makeService()->save($this->submission([$row], serial: '2023010101'));

        $this->assertSame(ZoneSaveOutcome::NO_CHANGES, $result->outcome);
        $this->assertSame([], $result->errors);
    }

    public function testASerialTheOperatorTypedIntoTheSoaRowIsKept(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->storedSoa('6', self::SOA));
        $written = null;
        $this->recordManager->expects($this->once())->method('editRecord')
            ->willReturnCallback(function (array $record) use (&$written): RecordWriteResult {
                $written = $record;
                return RecordWriteResult::ok();
            });
        $row = new ZoneEditRow('6', 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2025010101 10800 3600 604800 3600', 3600, 0, false, null);

        $this->makeService()->save($this->submission([$row], serial: '2023010101'));

        $this->assertSame('ns1.example.com hostmaster.example.com 2025010101 10800 3600 604800 3600', $written['content']);
    }

    public function testStaleUnfilteredFormIsRefusedWithoutRestoringRows(): void
    {
        $result = $this->makeService(['misc' => ['edit_conflict_resolution' => 'only_latest_version']])
            ->save($this->submission([$this->row('5', 'www', '192.0.2.9')], serial: '2023010101'));

        $this->assertSame(ZoneSaveOutcome::SERIAL_CONFLICT, $result->outcome);
        $this->assertSame([], $result->rejectedRecords);
    }

    public function testStaleFormStillSavesUnderLastWriterWins(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->recordManager->expects($this->once())->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->expects($this->once())->method('finalizeZone');

        $result = $this->makeService()->save($this->submission([$this->row('5', 'www', '192.0.2.9')], serial: '2023010101'));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
    }

    public function testUnchangedRowsAreNotWritten(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->recordManager->expects($this->never())->method('editRecord');

        $result = $this->makeService()->save($this->submission([$this->row('5', 'www', '192.0.2.1')]));

        $this->assertSame(ZoneSaveOutcome::NO_CHANGES, $result->outcome);
    }

    public function testRefusedRowReportsItsReasonAndSkipsTheSerialBump(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::failure('Invalid IP address'));
        $this->recordManager->expects($this->never())->method('finalizeZone');

        $result = $this->makeService()->save($this->submission([$this->row('5', 'www', 'nope')]));

        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
        $this->assertSame(['Invalid IP address'], $result->errors);
        $this->assertSame('error', ZoneSaveMessages::forResult($result)[0]);
    }

    public function testTruncatedPostSkipsIncompleteRowsAndTheZoneComment(): void
    {
        $this->zones->expects($this->never())->method('updateZoneComment');
        $this->recordManager->expects($this->never())->method('editRecord');
        $this->recordManager->expects($this->never())->method('finalizeZone');
        // The parser dropped the one row the post carried and flagged the truncation
        $result = $this->makeService()->save($this->submission(truncated: true, zoneComment: 'lost'));

        $this->assertSame(ZoneSaveOutcome::NOTHING_SAVED, $result->outcome);
        $this->assertTrue($result->truncated);
        $this->assertNull(ZoneSaveMessages::forResult($result));
    }

    public function testTruncatedPostStillWritesTheCompleteRows(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->recordManager->expects($this->once())->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->expects($this->once())->method('finalizeZone');

        $result = $this->makeService()->save($this->submission([$this->row('5', 'www', '192.0.2.9')], truncated: true));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertTrue($result->truncated);
    }

    public function testADisabledToggleIsWrittenAsAFlag(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $written = null;
        $this->recordManager->expects($this->once())->method('editRecord')
            ->willReturnCallback(function (array $record, bool $finalize, ?array $comment) use (&$written): RecordWriteResult {
                $written = [$record, $finalize, $comment];
                return RecordWriteResult::ok();
            });
        $row = $this->row('5', 'www', '192.0.2.1', disabled: true);

        $result = $this->makeService()->save($this->submission([$row]));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertSame(1, $written[0]['disabled']);
        $this->assertSame('5', $written[0]['rid']);
        $this->assertSame(self::ZONE_ID, (int)$written[0]['zid']);
        $this->assertArrayNotHasKey('comment', $written[0], 'no comment field was posted');
        $this->assertFalse($written[1]);
        $this->assertNull($written[2], 'comments are off, so none is passed to the write');
    }

    public function testARenameIsWrittenWithTheZoneSuffixRestored(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $written = null;
        $this->recordManager->expects($this->once())->method('editRecord')
            ->willReturnCallback(function (array $record) use (&$written): RecordWriteResult {
                $written = $record;
                return RecordWriteResult::ok();
            });

        $result = $this->makeService()->save($this->submission([$this->row('5', 'web', '192.0.2.1')]));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertSame('web.example.com', $written['name']);
        $this->assertSame('A', $written['type']);
        $this->assertSame(3600, (int)$written['ttl']);
        $this->assertSame(0, (int)$written['prio']);
        $this->assertSame(0, $written['disabled']);
    }

    public function testAPostedCommentIsPassedToTheWriteWhenCommentsAreShown(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->comments->method('findCommentByRecordId')->willReturn(null);
        $this->comments->method('findComment')->willReturn(null);
        $this->comments->expects($this->once())->method('updateCommentForRecord')
            ->with(self::ZONE_ID, 'www.example.com', 'A', 'hello', 5, 'alice');
        $this->recordManager->expects($this->once())->method('editRecord')
            ->with($this->anything(), false, ['content' => 'hello', 'account' => 'alice'])
            ->willReturn(RecordWriteResult::ok());
        $row = $this->row('5', 'www', '192.0.2.1', comment: 'hello');

        $result = $this->makeService(['interface' => ['show_record_comments' => true]])->save($this->submission([$row]));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
    }

    public function testACommentOnlyRecordEditKeepsTheSerialWhenUnchangedSavesKeepIt(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->comments->method('findCommentByRecordId')->willReturn(null);
        $this->comments->method('findComment')->willReturn(null);
        $this->comments->expects($this->once())->method('updateCommentForRecord');
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::unchanged());
        $this->recordManager->expects($this->never())->method('finalizeZone');

        $result = $this->makeService(['interface' => ['show_record_comments' => true], 'dns' => ['bump_serial_on_unchanged_save' => false]])
            ->save($this->submission([$this->row('5', 'www', '192.0.2.1', comment: 'hello')]));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertFalse($result->serialBumped);
    }

    public function testACommentOnlyRecordEditBumpsTheSerialByDefault(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->comments->method('findCommentByRecordId')->willReturn(null);
        $this->comments->method('findComment')->willReturn(null);
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID, true, false)
            ->willReturnCallback(fn() => $this->currentSoa = self::SOA_BUMPED);

        $result = $this->makeService(['interface' => ['show_record_comments' => true]])
            ->save($this->submission([$this->row('5', 'www', '192.0.2.1', comment: 'hello')]));

        $this->assertTrue($result->serialBumped);
    }

    public function testASerialTheBackendAlreadyMovedIsHandedOverAsWritten(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->comments->method('findCommentByRecordId')->willReturn(null);
        $this->comments->method('findComment')->willReturn(null);
        $this->recordManager->method('editRecord')->willReturnCallback(function (): RecordWriteResult {
            $this->currentSoa = self::SOA_BUMPED;

            return RecordWriteResult::ok();
        });
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID, true, true);

        $this->makeService(['interface' => ['show_record_comments' => true]])
            ->save($this->submission([$this->row('5', 'www', '192.0.2.1', comment: 'hello')]));
    }

    public function testASaveWhoseBumpDidNotMoveTheSerialIsNotReportedAsBumped(): void
    {
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID, true, false);

        $result = $this->makeService()->save($this->submission());

        $this->assertSame(ZoneSaveOutcome::NO_CHANGES, $result->outcome);
        $this->assertFalse($result->serialBumped);
        $this->assertSame(['info', 'Zone saved successfully. No record changes were made.'], ZoneSaveMessages::forResult($result));
    }

    public function testAnEditedLowercaseRowIsWrittenAndCommentedUnderItsUppercaseType(): void
    {
        $this->records->method('getRecordFromId')->willReturn(['type' => 'a'] + $this->stored('5', 'www', '192.0.2.1'));
        $this->comments->method('findCommentByRecordId')->willReturn(null);
        $this->comments->method('findComment')->willReturn(null);
        $this->comments->expects($this->once())->method('updateCommentForRecord')
            ->with(self::ZONE_ID, 'www.example.com', 'A', 'hello', 5, 'alice');
        $this->recordManager->expects($this->once())->method('editRecord')
            ->with($this->callback(fn(array $record): bool => $record['type'] === 'A'))
            ->willReturn(RecordWriteResult::ok());
        $row = new ZoneEditRow('5', 'www', 'a', '192.0.2.2', 3600, 0, false, 'hello');

        $result = $this->makeService(['interface' => ['show_record_comments' => true]])->save($this->submission([$row]));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
    }

    public function testAnUntouchedLowercaseRowIsNotRewritten(): void
    {
        $this->records->method('getRecordFromId')->willReturn(['type' => 'a'] + $this->stored('5', 'www', '192.0.2.1'));
        $this->recordManager->expects($this->never())->method('editRecord');

        $this->makeService()->save($this->submission([new ZoneEditRow('5', 'www', 'a', '192.0.2.1', 3600, 0, false, null)]));
    }

    /** @param array<string, array<string, mixed>> $overrides */
    private function makeService(array $overrides = []): ZoneEditService
    {
        $config = new FakeConfiguration(array_replace_recursive([
            'interface' => ['show_record_comments' => false, 'show_zone_comments' => true],
            'misc' => ['edit_conflict_resolution' => 'last_writer_wins', 'record_comments_sync' => false],
            'dns' => ['bump_serial_on_unchanged_save' => true],
        ], $overrides));

        return new ZoneEditService(
            $config,
            $this->permissions,
            $this->zones,
            $this->domains,
            $this->records,
            $this->recordManager,
            $this->soa,
            $this->comments,
            $this->commentSync,
            $this->createMock(AuditService::class),
            (bool)$config->get('interface', 'show_record_comments'),
            (bool)$config->get('interface', 'show_zone_comments')
        );
    }

    /** @param list<ZoneEditRow> $rows */
    private function submission(
        array $rows = [],
        bool $truncated = false,
        ?string $serial = null,
        bool $changedRowsOnly = false,
        ?string $zoneComment = null
    ): ZoneEditSubmission {
        return new ZoneEditSubmission(self::ZONE_ID, 'example.com', self::USER_ID, 'alice', $rows, $truncated, $serial, $changedRowsOnly, $zoneComment);
    }

    private function row(string $rid, string $name, string $content, bool $disabled = false, ?string $comment = null): ZoneEditRow
    {
        return new ZoneEditRow($rid, $name, 'A', $content, 3600, 0, $disabled, $comment);
    }

    /** @return array<string, mixed> The stored SOA row RecordLog compares against */
    private function storedSoa(string $id, string $content): array
    {
        return ['id' => $id, 'domain_id' => self::ZONE_ID, 'name' => 'example.com', 'type' => 'SOA', 'content' => $content, 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];
    }

    /** @return array<string, mixed> The stored row RecordLog compares against */
    private function stored(string $id, string $name, string $content): array
    {
        return ['id' => $id, 'domain_id' => self::ZONE_ID, 'name' => $name . '.example.com', 'type' => 'A', 'content' => $content, 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];
    }
}
