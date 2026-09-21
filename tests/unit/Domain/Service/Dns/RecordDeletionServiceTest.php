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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Service\Dns\RecordDeletionOutcome;
use Poweradmin\Domain\Service\Dns\RecordDeletionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;

#[CoversClass(RecordDeletionService::class)]
#[CoversClass(RecordDeletionOutcome::class)]
class RecordDeletionServiceTest extends TestCase
{
    private const ZONE_ID = 7;
    private const RECORD_ID = 42;

    /** @var array<string, mixed>|null */
    private ?array $record = ['id' => self::RECORD_ID, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 300, 'prio' => 0];
    private bool $reverseHandling = true;
    private RecordWriteResult $deleteResult;

    /** @var RecordManagerInterface&MockObject */
    private RecordManagerInterface $recordManager;

    /** @var ReverseRecordCreator&MockObject */
    private ReverseRecordCreator $reverse;

    /** @var AuditLoggerInterface&MockObject */
    private AuditLoggerInterface $audit;

    protected function setUp(): void
    {
        $this->deleteResult = RecordWriteResult::ok();
        $this->recordManager = $this->createMock(RecordManagerInterface::class);
        $this->recordManager->method('deleteRecord')->willReturnCallback(fn(): RecordWriteResult => $this->deleteResult);
        $this->reverse = $this->createMock(ReverseRecordCreator::class);
        $this->audit = $this->createMock(AuditLoggerInterface::class);
    }

    private function service(): RecordDeletionService
    {
        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getRecordFromId')->willReturnCallback(fn(): ?array => $this->record);
        return new RecordDeletionService($records, $this->recordManager, $this->reverse, $this->audit, $this->reverseHandling);
    }

    public function testAMissingRecordIsReportedWithoutTouchingTheBackend(): void
    {
        $this->record = null;
        $this->recordManager->expects($this->never())->method('deleteRecord');

        $outcome = $this->service()->deleteWithReverse(self::ZONE_ID, self::RECORD_ID, true, true);

        $this->assertTrue($outcome->notFound);
        $this->assertFalse($outcome->recordDeleted);
        $this->assertSame('Record not found.', $outcome->message);
    }

    public function testABackendRefusalCarriesItsMessageAndIsNotAudited(): void
    {
        $this->deleteResult = RecordWriteResult::backendFailure('refused');
        $this->audit->expects($this->never())->method('logRecordDelete');
        $this->reverse->expects($this->never())->method('deleteReverseRecord');

        $outcome = $this->service()->deleteWithReverse(self::ZONE_ID, self::RECORD_ID, true, true);

        $this->assertFalse($outcome->recordDeleted);
        $this->assertFalse($outcome->notFound);
        $this->assertSame('refused', $outcome->message);
    }

    public function testADeletedARecordIsAuditedAndItsPtrRemovedWhenAsked(): void
    {
        $this->audit->expects($this->once())->method('logRecordDelete')
            ->with(self::ZONE_ID, 'A', 'www.example.com', '192.0.2.1', 300, 0);
        $this->reverse->expects($this->once())->method('deleteReverseRecord')
            ->with('A', '192.0.2.1', 'www.example.com')->willReturn(true);
        $this->reverse->expects($this->never())->method('deleteForwardRecord');

        $outcome = $this->service()->deleteWithReverse(self::ZONE_ID, self::RECORD_ID, true, true);

        $this->assertTrue($outcome->recordDeleted);
        $this->assertTrue($outcome->ptrCandidate);
        $this->assertTrue($outcome->ptrDeleted);
        $this->assertFalse($outcome->forwardCandidate);
        $this->assertFalse($outcome->forwardDeleted);
    }

    public function testThePtrIsLeftAloneWhenNotAskedButTheRecordStaysACandidate(): void
    {
        $this->reverse->expects($this->never())->method('deleteReverseRecord');

        $outcome = $this->service()->deleteWithReverse(self::ZONE_ID, self::RECORD_ID, false, false);

        $this->assertTrue($outcome->ptrCandidate);
        $this->assertFalse($outcome->ptrDeleted);
    }

    public function testAPtrRecordLooksForItsForwardRecord(): void
    {
        $this->record = ['id' => self::RECORD_ID, 'name' => '1.2.0.192.in-addr.arpa', 'type' => 'PTR', 'content' => 'www.example.com', 'ttl' => 300];
        $this->reverse->expects($this->once())->method('deleteForwardRecord')
            ->with('1.2.0.192.in-addr.arpa', 'www.example.com')->willReturn(false);
        $this->audit->expects($this->once())->method('logRecordDelete')
            ->with(self::ZONE_ID, 'PTR', '1.2.0.192.in-addr.arpa', 'www.example.com', 300, null);

        $outcome = $this->service()->deleteWithReverse(self::ZONE_ID, self::RECORD_ID, true, true);

        $this->assertFalse($outcome->ptrCandidate);
        $this->assertTrue($outcome->forwardCandidate);
        $this->assertFalse($outcome->forwardDeleted);
    }

    public function testWithReverseHandlingOffNothingIsACandidate(): void
    {
        $this->reverseHandling = false;
        $this->reverse->expects($this->never())->method('deleteReverseRecord');

        $outcome = $this->service()->deleteWithReverse(self::ZONE_ID, self::RECORD_ID, true, true);

        $this->assertTrue($outcome->recordDeleted);
        $this->assertFalse($outcome->ptrCandidate);
        $this->assertFalse($outcome->forwardCandidate);
    }
}
