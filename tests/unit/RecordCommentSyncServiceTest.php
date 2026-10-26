<?php

namespace Poweradmin\Tests\Unit;

use Poweradmin\Application\Service\Record\RecordCommentService;
use Poweradmin\Application\Service\Record\RecordCommentSyncService;
use Poweradmin\Application\Service\Zone\ChangeApprovalContext;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use TestHelpers\PermissionServiceTestCase;

class RecordCommentSyncServiceTest extends PermissionServiceTestCase
{
    private const ADMIN = 1;
    private const OWNER = 2;

    private function permissions(): PermissionService
    {
        return $this->buildPermissionService(
            permissionsByUser: [self::OWNER => [Permission::PERM_ZONE_CONTENT_EDIT_OWN]],
            adminUserIds: [self::ADMIN],
            ownedZonesByUser: [self::OWNER => [1]]
        );
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

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
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

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
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

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updatePtrRecordComment(2, 'old.ptr.example.com', 'new.ptr.example.com', 'Updated comment', 'user');
    }

    public function testUpdateARecordCommentUpdatesARecordComment()
    {
        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->once())
            ->method('updateComment')
            ->with(2, 'old.example.com', 'A', 'new.example.com', 'A', 'Updated comment', 'user');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
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

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user', self::ADMIN);
    }

    public function testUpdateRelatedRecordCommentsUpdatesARecordCommentForPtrRecord()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getDomainIdByName')->willReturn(1);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->once())
            ->method('updateComment')
            ->with(1, 'ptr.example.com', 'A', 'ptr.example.com', 'A', 'Updated comment', 'user');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'PTR', 'content' => 'ptr.example.com'], 'Updated comment', 'user', self::ADMIN);
    }

    public function testUpdateRelatedRecordCommentsSkipsWhenPtrContentDomainNotFound()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getDomainIdByName')->willReturn(null);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())
            ->method('updateComment');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'PTR', 'content' => 'localhost'], 'Updated comment', 'user', self::ADMIN);
    }

    public function testUpdateRelatedRecordCommentsSkipsWhenPtrZoneNotFound()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getBestMatchingZoneIdFromName')->willReturn(-1);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())
            ->method('updateComment');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user', self::ADMIN);
    }

    public function testUpdateRelatedRecordCommentsWithEmptyDomainName()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getDomainIdByName')
            ->with('localhost')
            ->willReturn(null);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())
            ->method('updateComment');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'PTR', 'content' => 'localhost'], 'Updated comment', 'user', self::ADMIN);
    }

    public function testUpdateRelatedRecordCommentsUpdatesTheApexRecordWhenThePtrTargetIsAZone()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getDomainIdByName')
            ->willReturnCallback(fn(string $name) => $name === 'example.com' ? 1 : null);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->once())
            ->method('updateComment')
            ->with(1, 'example.com', 'A', 'example.com', 'A', 'Updated comment', 'user');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'PTR', 'content' => 'example.com'], 'Updated comment', 'user', self::ADMIN);
    }

    public function testUpdateRelatedRecordCommentsUpdatesAaaaRecordCommentForIp6ArpaPtr()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getDomainIdByName')->willReturn(1);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->once())
            ->method('updateComment')
            ->with(1, 'host.example.com', 'AAAA', 'host.example.com', 'AAAA', 'Updated comment', 'user');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments(
            $domainRepositoryMock,
            ['type' => 'PTR', 'name' => '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', 'content' => 'host.example.com'],
            'Updated comment',
            'user',
            self::ADMIN
        );
    }

    public function testUpdateRelatedRecordCommentsSkipsAReverseZoneTheUserCannotEdit()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getBestMatchingZoneIdFromName')->willReturn(2);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())->method('updateComment');
        $commentServiceMock->expects($this->never())->method('updateCommentForRecord');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user', self::OWNER);
    }

    public function testUpdateRelatedRecordCommentsSkipsAForwardZoneTheUserCannotEdit()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getDomainIdByName')->willReturn(7);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())->method('updateComment');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'PTR', 'content' => 'host.other.example'], 'Updated comment', 'user', self::OWNER);
    }

    public function testUpdateRelatedRecordCommentsReachesAnOwnedZone()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getDomainIdByName')->willReturn(1);

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->once())
            ->method('updateComment')
            ->with(1, 'ptr.example.com', 'A', 'ptr.example.com', 'A', 'Updated comment', 'user');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'PTR', 'content' => 'ptr.example.com'], 'Updated comment', 'user', self::OWNER);
    }

    public function testUpdateRelatedRecordCommentsSkipsASecondaryZone()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getBestMatchingZoneIdFromName')->willReturn(2);
        $domainRepositoryMock->method('getDomainType')->willReturn('SLAVE');

        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())->method('updateComment');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user', self::ADMIN);
    }

    public function testUpdateRelatedRecordCommentsSkipsAPtrZoneWhereTheUserMustFileAChangeRequest()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getBestMatchingZoneIdFromName')->willReturn(2);
        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())->method('updateComment');
        $approval = $this->createMock(ChangeApprovalContext::class);
        $approval->method('modeForZone')->willReturn(ChangeApprovalPolicy::MODE_REQUEST);

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions(), null, null, $approval);
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user', self::ADMIN);
    }

    public function testUpdateRelatedRecordCommentsSkipsAPtrZoneOutsideTheCallersLimit()
    {
        $domainRepositoryMock = $this->createMock(DomainRepositoryInterface::class);
        $domainRepositoryMock->method('getBestMatchingZoneIdFromName')->willReturn(2);
        $commentServiceMock = $this->createMock(RecordCommentService::class);
        $commentServiceMock->expects($this->never())->method('updateComment');

        $service = new RecordCommentSyncService($commentServiceMock, $this->permissions());
        $service->updateRelatedRecordComments($domainRepositoryMock, ['type' => 'A', 'content' => '192.0.2.1'], 'Updated comment', 'user', self::ADMIN, fn(int $zoneId): bool => $zoneId !== 2);
    }
}
