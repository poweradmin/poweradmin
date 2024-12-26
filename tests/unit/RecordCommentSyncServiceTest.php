<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Domain\Service\DnsRecord;

class RecordCommentSyncServiceTest extends TestCase
{
    public function testCommentsForPtrRecordCreatesCommentsForPtrAndARecords()
    {
        $commentServiceMock = $this->createMock(RecordCommentService::class);

        $receivedArgs = [];

        $commentServiceMock
            ->expects($this->exactly(2))
            ->method('createComment')
            ->willReturnCallback(function (...$args) use (&$receivedArgs) {
                $receivedArgs[] = $args;
            });

        $service = new RecordCommentSyncService($commentServiceMock);
        $service->syncCommentsForPtrRecord(1, 2, 'example.com', '1.0.168.192.in-addr.arpa', 'Test comment', 'user');

        $this->assertEquals([
            [1, 'example.com', 'A', 'Test comment', 'user'],
            [2, '1.0.168.192.in-addr.arpa', 'PTR', 'Test comment', 'user']
        ], $receivedArgs);
    }

    public function testCommentsForDomainRecordCreatesCommentsForDomainAndPtrRecords()
    {
        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $receivedArgs = [];

        $commentServiceMock
            ->expects($this->exactly(2))
            ->method('createComment')
            ->willReturnCallback(function (...$args) use (&$receivedArgs) {
                $receivedArgs[] = $args;
            });

        $service = new RecordCommentSyncService($commentServiceMock);
        $service->syncCommentsForDomainRecord(1, 2, '192.168.0.1', '1.0.168.192.in-addr.arpa', 'Test comment', 'user');

        $this->assertEquals([
            [2, '1.0.168.192.in-addr.arpa', 'PTR', 'Test comment', 'user'],
            [1, '192.168.0.1', 'A', 'Test comment', 'user']
        ], $receivedArgs);
    }

    public function testUpdatePtrRecordCommentUpdatesPtrRecordComment()
    {
        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->once())
            ->method('updateComment')
            ->with(2, 'old.ptr.example.com', 'PTR', 'new.ptr.example.com', 'PTR', 'Updated comment', 'user');

        $service = new RecordCommentSyncService($commentServiceMock);
        $service->updatePtrRecordComment(2, 'old.ptr.example.com', 'new.ptr.example.com', 'Updated comment', 'user');
    }

    public function testUpdateARecordCommentUpdatesARecordComment()
    {
        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->once())
            ->method('updateComment')
            ->with(2, 'old.example.com', 'A', 'new.example.com', 'A', 'Updated comment', 'user');

        $service = new RecordCommentSyncService($commentServiceMock);
        $service->updateARecordComment(2, 'old.example.com', 'new.example.com', 'Updated comment', 'user');
    }

    public function testUpdateRelatedRecordCommentsUpdatesPtrRecordCommentForARecord()
    {
        $dnsRecordMock = $this->createMock(DnsRecord::class);
        $dnsRecordMock->method('get_best_matching_zone_id_from_name')->willReturn(2);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->once())
            ->method('updateComment')
            ->with(2, '1.2.0.192.in-addr.arpa', 'PTR', '1.2.0.192.in-addr.arpa', 'PTR', 'Updated comment', 'user');

        $service = new RecordCommentSyncService($commentServiceMock);
        $service->updateRelatedRecordComments($dnsRecordMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user');
    }

    public function testUpdateRelatedRecordCommentsUpdatesARecordCommentForPtrRecord()
    {
        $dnsRecordMock = $this->createMock(DnsRecord::class);
        $dnsRecordMock->method('get_domain_id_by_name')->willReturn(1);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->once())
            ->method('updateComment')
            ->with(1, 'ptr.example.com', 'A', 'ptr.example.com', 'A', 'Updated comment', 'user');

        $service = new RecordCommentSyncService($commentServiceMock);
        $service->updateRelatedRecordComments($dnsRecordMock, ['type' => 'PTR', 'content' => 'ptr.example.com'], 'Updated comment', 'user');
    }
}