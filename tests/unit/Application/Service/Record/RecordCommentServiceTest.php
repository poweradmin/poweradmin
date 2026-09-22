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

namespace Poweradmin\Tests\Unit\Application\Service\Record;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Record\RecordCommentService;
use Poweradmin\Domain\Model\RecordComment;
use Poweradmin\Domain\Repository\RecordCommentRepositoryInterface;
use Poweradmin\Domain\Repository\RecordLinkedCommentRepositoryInterface;

/**
 * Characterizes the comment service on both backends: per-record writes go
 * through the linking table where one exists, legacy RRset comments are
 * migrated or shadowed, and an RRset-level write never touches the links.
 */
#[CoversClass(RecordCommentService::class)]
class RecordCommentServiceTest extends TestCase
{
    private const ZONE_ID = 3;
    private const RECORD_ID = 44;

    /** @var RecordCommentRepositoryInterface&MockObject */
    private RecordCommentRepositoryInterface $rrsets;

    /** @var RecordLinkedCommentRepositoryInterface&MockObject */
    private RecordLinkedCommentRepositoryInterface $links;

    protected function setUp(): void
    {
        $this->rrsets = $this->createMock(RecordCommentRepositoryInterface::class);
        $this->links = $this->createMock(RecordLinkedCommentRepositoryInterface::class);
    }

    private function sqlService(): RecordCommentService
    {
        return new RecordCommentService($this->rrsets, $this->links);
    }

    /** The API backend has no linking table, so there is no per-record repository. */
    private function apiService(): RecordCommentService
    {
        return new RecordCommentService($this->rrsets, null);
    }

    private function stored(string $text): RecordComment
    {
        return RecordComment::create(self::ZONE_ID, 'www.example.com', 'A', $text, 'alice');
    }

    // --------------------------------------------------------- per-record, SQL

    public function testCreatingAPerRecordCommentMigratesLegacySiblingsThenDropsTheLegacyRow(): void
    {
        $this->links->expects($this->once())->method('migrateLegacyComments')
            ->with(self::ZONE_ID, 'www.example.com', 'A', self::RECORD_ID)->willReturn(true);
        $this->rrsets->expects($this->once())->method('addForRecord')
            ->with(self::RECORD_ID, $this->callback(fn(RecordComment $c): bool => $c->getComment() === 'hello' && $c->getAccount() === 'alice'))
            ->willReturn($this->stored('hello'));
        $this->rrsets->expects($this->once())->method('deleteLegacyComment')->with(self::ZONE_ID, 'www.example.com', 'A');

        $comment = $this->sqlService()->createCommentForRecord(self::ZONE_ID, 'www.example.com', 'A', 'hello', self::RECORD_ID, 'alice');

        $this->assertSame('hello', $comment?->getComment());
    }

    public function testWithNothingMigratedTheLegacyRowIsLeftAlone(): void
    {
        $this->links->method('migrateLegacyComments')->willReturn(false);
        $this->rrsets->method('addForRecord')->willReturn($this->stored('hello'));
        $this->rrsets->expects($this->never())->method('deleteLegacyComment');

        $this->sqlService()->createCommentForRecord(self::ZONE_ID, 'www.example.com', 'A', 'hello', self::RECORD_ID);
    }

    public function testAnEmptyCommentRemovesTheRecordsCommentAndTheMigratedLegacyRow(): void
    {
        $this->links->method('migrateLegacyComments')->willReturn(true);
        $this->links->expects($this->once())->method('deleteByRecordId')->with(self::RECORD_ID)->willReturn(true);
        $this->rrsets->expects($this->once())->method('deleteLegacyComment')->with(self::ZONE_ID, 'www.example.com', 'A');
        $this->rrsets->expects($this->never())->method('addForRecord');

        $this->assertNull($this->sqlService()->createCommentForRecord(self::ZONE_ID, 'www.example.com', 'A', '', self::RECORD_ID));
    }

    public function testUpdatingToAnEmptyCommentBehavesLikeCreatingAnEmptyOne(): void
    {
        $this->links->method('migrateLegacyComments')->willReturn(true);
        $this->links->expects($this->once())->method('deleteByRecordId')->with(self::RECORD_ID)->willReturn(true);
        $this->rrsets->expects($this->once())->method('deleteLegacyComment');

        $this->assertNull($this->sqlService()->updateCommentForRecord(self::ZONE_ID, 'www.example.com', 'A', '', self::RECORD_ID));
    }

    public function testUpdatingAPerRecordCommentWritesThroughTheLink(): void
    {
        $this->links->expects($this->never())->method('migrateLegacyComments');
        $this->rrsets->expects($this->once())->method('addForRecord')->with(self::RECORD_ID, $this->anything())->willReturn($this->stored('new'));

        $this->assertSame('new', $this->sqlService()->updateCommentForRecord(self::ZONE_ID, 'www.example.com', 'A', 'new', self::RECORD_ID)?->getComment());
    }

    public function testFindByRecordIdAndDeleteByRecordIdGoThroughTheLinks(): void
    {
        $this->links->expects($this->once())->method('findByRecordId')->with(self::RECORD_ID)->willReturn($this->stored('x'));
        $this->links->expects($this->once())->method('deleteByRecordId')->with(self::RECORD_ID)->willReturn(true);

        $service = $this->sqlService();
        $this->assertSame('x', $service->findCommentByRecordId(self::RECORD_ID)?->getComment());
        $this->assertTrue($service->deleteCommentByRecordId(self::RECORD_ID));
    }

    // --------------------------------------------------------- per-record, API

    public function testOnTheApiBackendAnEmptyCommentShadowsALegacyRrsetCommentWithASentinel(): void
    {
        $this->rrsets->method('find')->willReturn($this->stored('shared'));
        $this->rrsets->expects($this->never())->method('deleteLegacyComment');
        $this->rrsets->expects($this->once())->method('addForRecord')
            ->with(self::RECORD_ID, $this->callback(fn(RecordComment $c): bool => $c->getComment() === ''))
            ->willReturn(null);

        $this->assertNull($this->apiService()->createCommentForRecord(self::ZONE_ID, 'www.example.com', 'A', '', self::RECORD_ID));
    }

    public function testOnTheApiBackendAnEmptyCommentWithNoLegacyRowWritesNothing(): void
    {
        $this->rrsets->method('find')->willReturn(null);
        $this->rrsets->expects($this->never())->method('addForRecord');
        $this->rrsets->expects($this->never())->method('deleteLegacyComment');

        $this->assertNull($this->apiService()->updateCommentForRecord(self::ZONE_ID, 'www.example.com', 'A', '', self::RECORD_ID));
    }

    public function testOnTheApiBackendACommentIsWrittenAtRrsetLevelAndNoLegacyRowIsDropped(): void
    {
        $this->rrsets->expects($this->once())->method('addForRecord')->willReturn($this->stored('hello'));
        $this->rrsets->expects($this->never())->method('deleteLegacyComment');

        $this->assertSame('hello', $this->apiService()->createCommentForRecord(self::ZONE_ID, 'www.example.com', 'A', 'hello', self::RECORD_ID)?->getComment());
    }

    public function testOnTheApiBackendPerRecordOperationsSayTheyAreUnsupported(): void
    {
        $service = $this->apiService();

        $this->assertFalse($service->supportsPerRecordComments());
        $this->assertTrue($this->sqlService()->supportsPerRecordComments());
        $this->assertNull($service->findCommentByRecordId(self::RECORD_ID));
        $this->assertFalse($service->deleteCommentByRecordId(self::RECORD_ID), 'no fake success without a linking table');
    }

    // ------------------------------------------------------------- RRset level

    public function testAnRrsetCommentIsAddedWithoutTouchingTheLinks(): void
    {
        $this->rrsets->expects($this->once())->method('add')->willReturnArgument(0);
        $this->rrsets->expects($this->never())->method('addForRecord');

        $comment = $this->sqlService()->createComment(self::ZONE_ID, 'www.example.com', 'A', 'hello', 'alice');

        $this->assertSame('hello', $comment?->getComment());
        $this->assertNull($this->sqlService()->createComment(self::ZONE_ID, 'www.example.com', 'A', ''));
    }

    public function testRenamingAnRrsetDropsTheOldCommentBeforeWritingTheNewOne(): void
    {
        $this->rrsets->expects($this->once())->method('delete')->with(self::ZONE_ID, 'old.example.com', 'A')->willReturn(true);
        $this->rrsets->expects($this->once())->method('update')
            ->with(self::ZONE_ID, 'old.example.com', 'A', $this->callback(fn(RecordComment $c): bool => $c->getName() === 'new.example.com'))
            ->willReturn($this->stored('moved'));

        $this->assertSame('moved', $this->sqlService()->updateComment(self::ZONE_ID, 'old.example.com', 'A', 'new.example.com', 'A', 'moved')?->getComment());
    }

    public function testAnEmptyRrsetUpdateDeletesTheComment(): void
    {
        $this->rrsets->expects($this->once())->method('delete')->with(self::ZONE_ID, 'www.example.com', 'A')->willReturn(true);
        $this->rrsets->expects($this->never())->method('update');

        $this->assertNull($this->sqlService()->updateComment(self::ZONE_ID, 'www.example.com', 'A', 'www.example.com', 'A', ''));
    }

    public function testDomainWideDeleteIsForwarded(): void
    {
        $this->rrsets->expects($this->once())->method('deleteByDomainId')->with(self::ZONE_ID);

        $this->sqlService()->deleteCommentsByDomainId(self::ZONE_ID);
    }
}
