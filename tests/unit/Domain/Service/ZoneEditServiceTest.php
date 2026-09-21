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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Application\Service\ZoneSaveMessages;
use Poweradmin\Domain\Enum\ZoneSaveOutcome;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\ZoneEditService;
use Poweradmin\Domain\Service\ZoneEditSubmission;
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
        $this->soa->method('getSOARecord')->willReturn(self::SOA);
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

        $result = $this->makeService()->save($this->submission(records: [], zoneComment: 'x'));

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
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID);

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
        $this->recordManager->expects($this->once())->method('finalizeZone')->with(self::ZONE_ID);

        $result = $this->makeService(['dns' => ['bump_serial_on_unchanged_save' => false]])
            ->save($this->submission(zoneComment: 'new comment'));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertTrue($result->serialBumped);
    }

    public function testStaleFormIsRefusedOnlyUnderTheStrictStrategy(): void
    {
        $this->recordManager->expects($this->never())->method('editRecord');
        $this->recordManager->expects($this->never())->method('finalizeZone');
        $rows = ['5' => $this->row('5', 'www', '192.0.2.9')];

        $result = $this->makeService(['misc' => ['edit_conflict_resolution' => 'only_latest_version']])
            ->save($this->submission(records: $rows, serial: '2023010101', changedRowsOnly: true, zoneComment: 'typed'));

        $this->assertSame(ZoneSaveOutcome::SERIAL_CONFLICT, $result->outcome);
        $this->assertSame($rows, $result->rejectedRecords);
        $this->assertSame('typed', $result->rejectedZoneComment);
        $this->assertSame('warning', ZoneSaveMessages::forResult($result)[0]);
    }

    public function testStaleUnfilteredFormIsRefusedWithoutRestoringRows(): void
    {
        $result = $this->makeService(['misc' => ['edit_conflict_resolution' => 'only_latest_version']])
            ->save($this->submission(records: ['5' => $this->row('5', 'www', '192.0.2.9')], serial: '2023010101'));

        $this->assertSame(ZoneSaveOutcome::SERIAL_CONFLICT, $result->outcome);
        $this->assertSame([], $result->rejectedRecords);
    }

    public function testStaleFormStillSavesUnderLastWriterWins(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->recordManager->expects($this->once())->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->expects($this->once())->method('finalizeZone');

        $result = $this->makeService()->save($this->submission(records: ['5' => $this->row('5', 'www', '192.0.2.9')], serial: '2023010101'));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
    }

    public function testUnchangedRowsAreNotWritten(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->recordManager->expects($this->never())->method('editRecord');

        $result = $this->makeService()->save($this->submission(records: ['5' => $this->row('5', 'www', '192.0.2.1')]));

        $this->assertSame(ZoneSaveOutcome::NO_CHANGES, $result->outcome);
    }

    public function testRefusedRowReportsItsReasonAndSkipsTheSerialBump(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::failure('Invalid IP address'));
        $this->recordManager->expects($this->never())->method('finalizeZone');

        $result = $this->makeService()->save($this->submission(records: ['5' => $this->row('5', 'www', 'nope')]));

        $this->assertSame(ZoneSaveOutcome::WRITE_FAILED, $result->outcome);
        $this->assertSame(['Invalid IP address'], $result->errors);
        $this->assertSame('error', ZoneSaveMessages::forResult($result)[0]);
    }

    public function testTruncatedPostSkipsIncompleteRowsAndTheZoneComment(): void
    {
        $this->zones->expects($this->never())->method('updateZoneComment');
        $this->recordManager->expects($this->never())->method('editRecord');
        $this->recordManager->expects($this->never())->method('finalizeZone');
        $row = $this->row('5', 'www', '192.0.2.9');
        unset($row['_complete']);

        $result = $this->makeService()->save($this->submission(records: ['5' => $row], formComplete: false, zoneComment: 'lost'));

        $this->assertSame(ZoneSaveOutcome::NOTHING_SAVED, $result->outcome);
        $this->assertTrue($result->truncated);
        $this->assertNull(ZoneSaveMessages::forResult($result));
    }

    public function testTruncatedPostStillWritesTheCompleteRows(): void
    {
        $this->records->method('getRecordFromId')->willReturn($this->stored('5', 'www', '192.0.2.1'));
        $this->recordManager->expects($this->once())->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->recordManager->expects($this->once())->method('finalizeZone');
        $partial = $this->row('6', 'mail', '192.0.2.2');
        unset($partial['_complete']);

        $result = $this->makeService()->save($this->submission(
            records: ['5' => $this->row('5', 'www', '192.0.2.9'), '6' => $partial],
            formComplete: false
        ));

        $this->assertSame(ZoneSaveOutcome::UPDATED, $result->outcome);
        $this->assertTrue($result->truncated);
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
            $this->createMock(AuditService::class)
        );
    }

    private function submission(
        ?array $records = null,
        bool $formComplete = true,
        ?string $serial = null,
        bool $changedRowsOnly = false,
        ?string $zoneComment = null
    ): ZoneEditSubmission {
        return new ZoneEditSubmission(self::ZONE_ID, 'example.com', self::USER_ID, 'alice', $records, $formComplete, $serial, $changedRowsOnly, $zoneComment);
    }

    /** @return array<string, mixed> A posted row as the editor sends it */
    private function row(string $rid, string $name, string $content): array
    {
        return ['rid' => $rid, 'zid' => self::ZONE_ID, 'name' => $name, 'type' => 'A', 'content' => $content, 'ttl' => '3600', 'prio' => '0', '_complete' => '1'];
    }

    /** @return array<string, mixed> The stored row RecordLog compares against */
    private function stored(string $id, string $name, string $content): array
    {
        return ['id' => $id, 'domain_id' => self::ZONE_ID, 'name' => $name . '.example.com', 'type' => 'A', 'content' => $content, 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];
    }
}
