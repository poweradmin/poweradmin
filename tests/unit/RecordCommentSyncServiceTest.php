<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use PDO;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;

class RecordCommentSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        UserContextService::setApiUserContext(7, 'user');
    }

    protected function tearDown(): void
    {
        UserContextService::clearApiUserContext();
    }

    private function permissions(string $editLevel = 'own'): PermissionService
    {
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('getEditPermissionLevelForZone')->willReturn($editLevel);

        return $permissionService;
    }

    private function syncService(RecordCommentService $commentService, string $editLevel = 'own'): RecordCommentSyncService
    {
        return new RecordCommentSyncService($commentService, null, null, $this->permissions($editLevel), $this->createMock(PDO::class));
    }

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
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getBestMatchingZoneIdFromName')->willReturn(2);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->once())
            ->method('updateComment')
            ->with(2, '1.2.0.192.in-addr.arpa', 'PTR', '1.2.0.192.in-addr.arpa', 'PTR', 'Updated comment', 'user');

        $service = $this->syncService($commentServiceMock);
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user');
    }

    public function testUpdateRelatedRecordCommentsUpdatesARecordCommentForPtrRecord()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getDomainIdByName')->willReturn(1);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->once())
            ->method('updateComment')
            ->with(1, 'ptr.example.com', 'A', 'ptr.example.com', 'A', 'Updated comment', 'user');

        $service = $this->syncService($commentServiceMock);
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'PTR', 'content' => 'ptr.example.com'], 'Updated comment', 'user');
    }

    public function testUpdateRelatedRecordCommentsSkipsWhenPtrContentDomainNotFound()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getDomainIdByName')->willReturn(null);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())
            ->method('updateComment');

        $service = new RecordCommentSyncService($commentServiceMock);
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'PTR', 'content' => 'localhost'], 'Updated comment', 'user');
    }

    public function testUpdateRelatedRecordCommentsSkipsWhenPtrZoneNotFound()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getBestMatchingZoneIdFromName')->willReturn(-1);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())
            ->method('updateComment');

        $service = new RecordCommentSyncService($commentServiceMock);
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user');
    }

    public function testUpdateRelatedRecordCommentsWithEmptyDomainName()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getDomainIdByName')
            ->with('')
            ->willReturn(null);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())
            ->method('updateComment');

        $service = new RecordCommentSyncService($commentServiceMock);
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'PTR', 'content' => 'localhost'], 'Updated comment', 'user');
    }

    public function testUpdateRelatedRecordCommentsSkipsAPtrZoneTheUserCannotEdit()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getBestMatchingZoneIdFromName')->willReturn(2);
        $domainRepositoryMock->method('getDomainType')->willReturn('MASTER');

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())->method('updateComment');

        $service = $this->syncService($commentServiceMock, 'none');
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user');
    }

    public function testUpdateRelatedRecordCommentsSkipsAForwardZoneTheUserCannotEdit()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getDomainIdByName')->willReturn(1);
        $domainRepositoryMock->method('getDomainType')->willReturn('MASTER');

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())->method('updateComment');

        $service = $this->syncService($commentServiceMock, 'none');
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'PTR', 'content' => 'ptr.example.com'], 'Updated comment', 'user');
    }

    public function testUpdateRelatedRecordCommentsSkipsAReadOnlyZone()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getBestMatchingZoneIdFromName')->willReturn(2);
        $domainRepositoryMock->method('getDomainType')->willReturn('SLAVE');

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())->method('updateComment');

        $service = $this->syncService($commentServiceMock, 'all');
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user');
    }

    public function testUpdateRelatedRecordCommentsSkipsWithoutAPermissionService()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getBestMatchingZoneIdFromName')->willReturn(2);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())->method('updateComment');

        $service = new RecordCommentSyncService($commentServiceMock);
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user');
    }
}
