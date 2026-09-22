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
use Poweradmin\Application\Service\Record\RecordCommentSyncService;
use Poweradmin\Application\Service\Record\RecordEditRequest;
use Poweradmin\Application\Service\Record\RecordEditResult;
use Poweradmin\Application\Service\Record\RecordEditService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\RecordComment;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The edit flow both the edit-record page and the API PUT run: what reaches
 * the record manager, when an SOA is bumped, how a re-keyed record is found
 * again, and what the PTR sync and the comment step do with the outcome.
 */
#[CoversClass(RecordEditService::class)]
#[CoversClass(RecordEditResult::class)]
#[CoversClass(RecordEditRequest::class)]
class RecordEditServiceTest extends TestCase
{
    private const ZONE_ID = 5;
    private const RECORD_ID = 42;

    /** @var array<string, mixed> */
    private array $current = ['id' => self::RECORD_ID, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0];

    /** @var array<string, mixed>|null */
    private ?array $reread = ['id' => self::RECORD_ID, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.9', 'ttl' => 300, 'prio' => 0];

    private RecordWriteResult $writeResult;

    /** @var array<string, array<string, mixed>> */
    private array $config = ['dns' => ['bump_serial_on_unchanged_save' => true], 'misc' => ['record_comments_sync' => false]];

    /** @var RecordManagerInterface&MockObject */
    private RecordManagerInterface $recordManager;

    /** @var RecordRepositoryInterface&MockObject */
    private RecordRepositoryInterface $records;

    /** @var DomainRepositoryInterface&MockObject */
    private DomainRepositoryInterface $domains;

    /** @var SOARecordManagerInterface&MockObject */
    private SOARecordManagerInterface $soa;

    /** @var ReverseRecordCreator&MockObject */
    private ReverseRecordCreator $reverse;

    /** @var RecordCommentService&MockObject */
    private RecordCommentService $comments;

    /** @var RecordCommentSyncService&MockObject */
    private RecordCommentSyncService $commentSync;

    /** @var AuditLoggerInterface&MockObject */
    private AuditLoggerInterface $audit;

    protected function setUp(): void
    {
        $this->writeResult = RecordWriteResult::ok();
        $this->recordManager = $this->createMock(RecordManagerInterface::class);
        $this->recordManager->method('editRecord')->willReturnCallback(fn(): RecordWriteResult => $this->writeResult);
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('getRecordFromId')->willReturnCallback(fn(): ?array => $this->reread);
        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->soa = $this->createMock(SOARecordManagerInterface::class);
        $this->reverse = $this->createMock(ReverseRecordCreator::class);
        $this->comments = $this->createMock(RecordCommentService::class);
        $this->commentSync = $this->createMock(RecordCommentSyncService::class);
        $this->audit = $this->createMock(AuditLoggerInterface::class);
    }

    private function service(): RecordEditService
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $this->config[$group][$key] ?? $default
        );

        return new RecordEditService(
            $this->recordManager,
            $this->records,
            $this->domains,
            $this->soa,
            $this->reverse,
            $this->comments,
            $this->commentSync,
            $this->audit,
            $config,
            new NullLogger()
        );
    }

    /** @param array<string, mixed> $overrides */
    private function request(array $overrides = []): RecordEditRequest
    {
        $fields = $overrides + [
            'zoneId' => self::ZONE_ID,
            'zoneName' => 'example.com',
            'recordId' => self::RECORD_ID,
            'current' => $this->current,
            'name' => 'www',
            'type' => 'A',
            'content' => '192.0.2.9',
            'ttl' => 300,
            'prio' => 0,
            'disabled' => 0,
            'comment' => null,
            'syncPtr' => false,
            'bumpSoaSerial' => false,
            'username' => 'alice',
        ];

        return new RecordEditRequest(...$fields);
    }

    public function testTheWriteCarriesThePunycodeFqdnAndTheSubmittedFields(): void
    {
        $this->recordManager->expects($this->once())->method('editRecord')
            ->with([
                'rid' => self::RECORD_ID,
                'zid' => self::ZONE_ID,
                'name' => 'xn--bcher-kva.example.com',
                'type' => 'CNAME',
                'content' => 'xn--mnchen-3ya.example.com',
                'ttl' => 300,
                'prio' => 0,
                'disabled' => 1,
            ], true, null)
            ->willReturn(RecordWriteResult::ok());

        $result = $this->service()->edit($this->request(['name' => 'bücher', 'type' => 'CNAME', 'content' => 'münchen.example.com', 'disabled' => 1]));

        $this->assertTrue($result->isOk());
        $this->assertSame(self::RECORD_ID, $result->recordId);
        $this->assertNull($result->ptrUpdated);
    }

    public function testARefusedWriteStopsEverythingElse(): void
    {
        $this->writeResult = RecordWriteResult::failure('Invalid IPv4 address.');
        $this->soa->expects($this->never())->method('updateSOASerial');
        $this->reverse->expects($this->never())->method('updateReverseRecord');
        $this->audit->expects($this->never())->method('logRecordEdit');
        $this->comments->expects($this->never())->method('updateCommentForRecord');

        $result = $this->service()->edit($this->request(['comment' => 'x', 'syncPtr' => true, 'bumpSoaSerial' => true]));

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid IPv4 address.', $result->write->message);
        $this->assertSame([], $result->record);
    }

    public function testTheEditIsAuditedWithTheRowBeforeAndTheRereadRowAfter(): void
    {
        $this->audit->expects($this->once())->method('logRecordEdit')->with(self::ZONE_ID, $this->current, $this->reread);
        $this->audit->expects($this->never())->method('logApiRecordEdit');

        $this->service()->edit($this->request());
    }

    public function testAnApiEditIsAuditedOnceAsAnApiEditWithTheRereadRow(): void
    {
        $this->audit->expects($this->never())->method('logRecordEdit');
        $this->audit->expects($this->once())->method('logApiRecordEdit')
            ->with(self::ZONE_ID, $this->reread['name'], $this->reread['type'], $this->reread['content']);

        $this->service()->edit($this->request(['origin' => AuditLoggerInterface::ORIGIN_API]));
    }

    public function testAReKeyedRecordIsFoundAgainByItsSubmittedValues(): void
    {
        $this->reread = null;
        $this->records->expects($this->once())->method('getNewRecordId')
            ->with(self::ZONE_ID, 'www.example.com', 'A', '192.0.2.9')
            ->willReturn('enc-77');

        $result = $this->service()->edit($this->request());

        $this->assertSame('enc-77', $result->recordId);
        $this->assertSame(
            ['type' => 'A', 'name' => 'www.example.com', 'content' => '192.0.2.9', 'ttl' => 300, 'prio' => 0, 'disabled' => 0],
            $result->record
        );
    }

    public function testTheOldIdSurvivesWhenNoNewOneIsFound(): void
    {
        $this->reread = null;
        $this->records->method('getNewRecordId')->willReturn(null);

        $this->assertSame(self::RECORD_ID, $this->service()->edit($this->request())->recordId);
    }

    // -------------------------------------------------------------- SOA serial

    public function testAnSoaIsBumpedOnlyWhenTheCallerAsksForIt(): void
    {
        $this->soa->expects($this->never())->method('updateSOASerial');

        $this->service()->edit($this->request(['type' => 'SOA', 'bumpSoaSerial' => false]));
    }

    public function testAnSoaEditIsBumpedByDefault(): void
    {
        $this->soa->expects($this->once())->method('updateSOASerial')->with(self::ZONE_ID);

        $this->service()->edit($this->request(['type' => 'SOA', 'bumpSoaSerial' => true]));
    }

    public function testAnUnchangedSoaIsNotBumpedWhenTheInstallOptedOut(): void
    {
        $this->config['dns']['bump_serial_on_unchanged_save'] = false;
        $this->reread = $this->current;
        $this->soa->expects($this->never())->method('updateSOASerial');

        $this->service()->edit($this->request(['type' => 'SOA', 'bumpSoaSerial' => true]));
    }

    public function testAChangedSoaIsStillBumpedWhenTheInstallOptedOut(): void
    {
        $this->config['dns']['bump_serial_on_unchanged_save'] = false;
        $this->soa->expects($this->once())->method('updateSOASerial');

        $this->service()->edit($this->request(['type' => 'SOA', 'bumpSoaSerial' => true]));
    }

    public function testANonSoaEditIsNeverBumpedByTheService(): void
    {
        $this->soa->expects($this->never())->method('updateSOASerial');

        $this->service()->edit($this->request(['bumpSoaSerial' => true]));
    }

    // ------------------------------------------------------------- PTR sync

    public function testThePtrFollowsTheRereadValuesWhenAsked(): void
    {
        $this->reverse->expects($this->once())->method('updateReverseRecord')
            ->with('A', '192.0.2.1', 'www.example.com', 'A', '192.0.2.9', 'www.example.com', self::ZONE_ID, 300, 0)
            ->willReturn(['success' => true, 'message' => 'PTR record updated']);

        $result = $this->service()->edit($this->request(['syncPtr' => true]));

        $this->assertTrue($result->ptrUpdated);
        $this->assertFalse($result->ptrFailed());
        $this->assertSame('PTR record updated', $result->ptrMessage);
    }

    public function testAFailedPtrSyncIsReportedButDoesNotFailTheEdit(): void
    {
        $this->reverse->method('updateReverseRecord')->willReturn(['success' => false, 'message' => 'no reverse zone']);

        $result = $this->service()->edit($this->request(['syncPtr' => true]));

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->ptrFailed());
        $this->assertSame('no reverse zone', $result->ptrMessage);
    }

    public function testAThrowingPtrSyncIsReportedAsAFailure(): void
    {
        $this->reverse->method('updateReverseRecord')->willThrowException(new RuntimeException('backend down'));
        $this->audit->expects($this->once())->method('logRecordEdit');

        $result = $this->service()->edit($this->request(['syncPtr' => true]));

        $this->assertTrue($result->ptrFailed());
        $this->assertSame('backend down', $result->ptrMessage);
    }

    public function testThePtrIsLeftAloneWhenNeitherSideIsAnAddress(): void
    {
        $this->current['type'] = 'TXT';
        $this->reverse->expects($this->never())->method('updateReverseRecord');

        $result = $this->service()->edit($this->request(['type' => 'TXT', 'content' => 'hello', 'syncPtr' => true]));

        $this->assertNull($result->ptrUpdated);
    }

    public function testATypeChangeAwayFromAnAddressStillSyncsThePtr(): void
    {
        $this->reverse->expects($this->once())->method('updateReverseRecord')
            ->with('A', '192.0.2.1', 'www.example.com', 'TXT')
            ->willReturn(['success' => true, 'message' => 'Stale PTR record removed.']);

        $this->service()->edit($this->request(['type' => 'TXT', 'content' => 'hello', 'syncPtr' => true]));
    }

    // -------------------------------------------------------------- comments

    public function testASuppliedCommentGoesWithTheWriteAndOntoTheRecord(): void
    {
        $this->recordManager->expects($this->once())->method('editRecord')
            ->with($this->anything(), true, ['content' => 'a note', 'account' => 'alice'])
            ->willReturn(RecordWriteResult::ok());
        $this->comments->expects($this->once())->method('updateCommentForRecord')
            ->with(self::ZONE_ID, 'www.example.com', 'A', 'a note', self::RECORD_ID, 'alice');
        $this->commentSync->expects($this->never())->method('updateRelatedRecordComments');

        $this->service()->edit($this->request(['comment' => 'a note']));
    }

    public function testASuppliedCommentIsSyncedToRelatedRecordsWhenTheInstallAsksForIt(): void
    {
        $this->config['misc']['record_comments_sync'] = true;
        $this->commentSync->expects($this->once())->method('updateRelatedRecordComments')
            ->with($this->domains, $this->reread, 'a note', 'alice');

        $this->service()->edit($this->request(['comment' => 'a note']));
    }

    public function testAnEmptyCommentStillReachesTheCommentServiceSoItCanClear(): void
    {
        $this->comments->expects($this->once())->method('updateCommentForRecord')
            ->with(self::ZONE_ID, 'www.example.com', 'A', '', self::RECORD_ID, 'alice');

        $this->service()->edit($this->request(['comment' => '']));
    }

    public function testAReKeyedRecordLinksTheCommentToItsNewId(): void
    {
        $this->reread = null;
        $this->records->method('getNewRecordId')->willReturn(77);
        $this->comments->expects($this->once())->method('updateCommentForRecord')
            ->with(self::ZONE_ID, 'www.example.com', 'A', 'a note', 77, 'alice');

        $this->service()->edit($this->request(['comment' => 'a note']));
    }

    public function testWithoutACommentARenameCarriesTheExistingOneAlong(): void
    {
        $this->reread['name'] = 'web.example.com';
        $this->comments->method('findComment')->with(self::ZONE_ID, 'www.example.com', 'A')
            ->willReturn(RecordComment::create(self::ZONE_ID, 'www.example.com', 'A', 'keep me', 'someone'));
        $this->comments->expects($this->once())->method('updateComment')
            ->with(self::ZONE_ID, 'www.example.com', 'A', 'web.example.com', 'A', 'keep me', 'alice');
        $this->comments->expects($this->never())->method('updateCommentForRecord');

        $this->service()->edit($this->request(['name' => 'web']));
    }

    public function testWithoutACommentAnUnrenamedEditLeavesCommentsAlone(): void
    {
        $this->comments->expects($this->never())->method('findComment');
        $this->comments->expects($this->never())->method('updateComment');

        $this->service()->edit($this->request());
    }
}
