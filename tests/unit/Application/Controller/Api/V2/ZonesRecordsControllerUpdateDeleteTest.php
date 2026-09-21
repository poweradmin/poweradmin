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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Application\Service\RecordEditService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Model\RecordComment;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Characterization of PUT and DELETE on /api/v2/zones/{id}/records/{record_id}:
 * malformed ids, the two record-type permission checks an update runs, and the
 * fallback the response takes when the row's id changed under it.
 */
class ZonesRecordsControllerUpdateDeleteTest extends V2ControllerTestCase
{

    private const USER_ID = 6;
    private const ZONE_ID = 31;
    private const ZONE_NAME = 'example.com';
    private const RECORD_ID = 55;

    private ApiPermissionService&MockObject $permissions;
    private ZoneReadRepositoryInterface&MockObject $zones;
    private RecordRepositoryInterface&MockObject $records;
    private RecordManagerInterface&MockObject $recordManager;
    private ReverseRecordCreator&MockObject $reverseCreator;
    private RecordCommentService&MockObject $comments;
    private RecordCommentSyncService&MockObject $commentSync;

    /** @var array<string, mixed> */
    private array $zoneRow = ['id' => self::ZONE_ID, 'name' => self::ZONE_NAME, 'type' => 'MASTER'];
    private ApiKeyScope $scope;
    private int|string $recordIdParameter = self::RECORD_ID;

    protected function setUp(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->zones = $this->createMock(ZoneReadRepositoryInterface::class);
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->recordManager = $this->createMock(RecordManagerInterface::class);
        $this->reverseCreator = $this->createMock(ReverseRecordCreator::class);
        $this->comments = $this->createMock(RecordCommentService::class);
        $this->commentSync = $this->createMock(RecordCommentSyncService::class);
        $this->scope = ApiKeyScope::unrestricted();

        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('canEditZoneRecord')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function unusableRecordIdProvider(): array
    {
        return [
            'empty string' => [''],
            // "0" normalizes to int 0, which is falsy and so reads as no id at all.
            'zero' => [0],
            'string zero' => ['0'],
        ];
    }

    #[DataProvider('unusableRecordIdProvider')]
    public function testAnUnusableRecordIdIs400OnUpdate(int|string $recordId): void
    {
        $this->recordIdParameter = $recordId;
        $this->zones->expects($this->never())->method('getZoneById');

        $response = $this->update(['content' => '192.0.2.9']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Valid zone ID and record ID are required', $this->messageOf($response));
    }

    #[DataProvider('unusableRecordIdProvider')]
    public function testAnUnusableRecordIdIs400OnDelete(int|string $recordId): void
    {
        $this->recordIdParameter = $recordId;

        $response = $this->delete();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Valid zone ID and record ID are required', $this->messageOf($response));
    }

    public function testANonNumericRecordIdIsPassedThroughAsAString(): void
    {
        // API-backend ids are opaque strings; they must reach the repository intact.
        $this->recordIdParameter = 'abc123';
        $this->records->expects($this->once())->method('getRecordById')->with('abc123')->willReturn(null);

        $response = $this->delete();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Record not found in this zone', $this->messageOf($response));
    }

    public function testARecordBelongingToAnotherZoneIs404(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord(['domain_id' => 999]));

        $response = $this->update(['content' => '192.0.2.9']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Record not found in this zone', $this->messageOf($response));
    }

    public function testTheRecordIsResolvedBeforeTheBodyIsEvenParsed(): void
    {
        // A malformed body on a record that does not exist answers 404, not 400.
        $this->records->method('getRecordById')->willReturn(null);

        $response = $this->updateRaw('"nonsense"');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAMalformedBodyOnAnExistingRecordIs400(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());

        $response = $this->updateRaw('"nonsense"');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON in request body', $this->messageOf($response));
    }

    public function testTheStoredRecordTypeIsCheckedBeforeTheSubmittedOne(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord(['type' => 'SOA']));
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $this->permissions->expects($this->once())
            ->method('canEditZoneRecord')
            ->with(self::USER_ID, self::ZONE_ID, 'SOA', 'MASTER', 'www.example.com', self::ZONE_NAME)
            ->willReturn(false);

        $response = $this->update(['content' => '192.0.2.9']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to edit this record type', $this->messageOf($response));
    }

    public function testRetypingIntoAForbiddenTypeIsRefusedBySecondCheck(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $this->permissions->method('canEditZoneRecord')->willReturnCallback(
            static fn(int $u, int $z, string $type): bool => $type !== 'NS'
        );

        $response = $this->update(['type' => 'ns', 'content' => 'ns1.example.net']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to edit this record type', $this->messageOf($response));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedUpdateFieldProvider(): array
    {
        return [
            'integer type' => [['type' => 7]],
            'array content' => [['content' => []]],
            'non-numeric ttl' => [['ttl' => 'soon']],
            'non-numeric priority' => [['priority' => 'high']],
            'unparseable disabled' => [['disabled' => 'perhaps']],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('malformedUpdateFieldProvider')]
    public function testAMalformedFieldIs400WithOneSharedMessage(array $body): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());

        $response = $this->update($body);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid field types in request body', $this->messageOf($response));
    }

    /**
     * Pinned as-is, and it looks wrong: an array 'name' reaches the (string) cast
     * at ZonesRecordsController.php:681, so PHP emits "Array to string conversion"
     * and the record-type permission check is run against the literal "Array"
     * before the type guard below finally answers 400. The refusal is correct; the
     * warning and the bogus permission subject are not.
     */
    public function testAnArrayNameIs400BeforeThePermissionCheckSeesIt(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $subjects = [];
        $this->permissions->method('canEditZoneRecord')->willReturnCallback(
            static function (int $u, int $z, string $type, ?string $zt, ?string $name) use (&$subjects): bool {
                $subjects[] = $name;
                return true;
            }
        );

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $response = $this->update(['name' => ['www']]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid field types in request body', $this->messageOf($response));
        $this->assertSame([], $warnings);
        // The record-type check ran once on the stored record, never on the bogus name
        $this->assertNotContains('Array.example.com', $subjects);
    }

    public function testANegativeTtlIs400OnUpdate(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());

        $response = $this->update(['ttl' => -1]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('TTL must not be negative', $this->messageOf($response));
    }

    public function testAZeroTtlIsAcceptedOnUpdate(): void
    {
        // TTLValidator allows 0, so the API must not refuse it either; assert the
        // value reaches the write rather than merely that the request is not a 400
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->recordManager->expects($this->once())
            ->method('editRecord')
            ->with($this->callback(static fn(array $record): bool => $record['ttl'] === 0))
            ->willReturn(RecordWriteResult::ok());

        $response = $this->update(['ttl' => 0]);

        $this->assertNotSame(400, $response->getStatusCode());
    }

    public function testADisabledValueOtherThanZeroOrOneIs400OnUpdate(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());

        $response = $this->update(['disabled' => 3]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Disabled field must be 0 or 1', $this->messageOf($response));
    }

    public function testOmittedFieldsKeepTheStoredValues(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->recordManager->expects($this->once())
            ->method('editRecord')
            ->with([
                'rid' => self::RECORD_ID,
                'zid' => self::ZONE_ID,
                'name' => 'www.example.com',
                'type' => 'A',
                'content' => '192.0.2.9',
                'ttl' => 3600,
                'prio' => 0,
                'disabled' => 0,
            ])
            ->willReturn(RecordWriteResult::ok());

        $response = $this->update(['content' => '192.0.2.9']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAFailedEditKeepsTheManagersStatusAndReason(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::failure('Invalid IPv4 address', 400));

        $response = $this->update(['content' => 'not-an-ip']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid IPv4 address', $this->messageOf($response));
    }

    public function testABackendFaultOnEditIsGenericised(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::backendFailure('boom'));

        $response = $this->update(['content' => '192.0.2.9']);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Failed to update record', $this->messageOf($response));
    }

    public function testASuccessfulUpdateReturnsTheRereadRow(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->records->method('getRecordFromId')->willReturn($this->existingRecord(['content' => '192.0.2.9', 'ttl' => 60]));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());

        $response = $this->update(['content' => '192.0.2.9']);
        $record = $this->decode($response)['data']['record'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Record updated successfully', $this->messageOf($response));
        $this->assertSame('www', $record['name']);
        $this->assertSame('192.0.2.9', $record['content']);
        $this->assertSame(60, $record['ttl']);
        $this->assertFalse($record['ptr_updated']);
    }

    public function testTheUpdateResponseBodyIsTheDocumentedEnvelope(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->records->method('getRecordFromId')->willReturn($this->existingRecord(['content' => '192.0.2.9', 'ttl' => 60, 'disabled' => true, 'auth' => true]));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());

        $response = $this->update(['content' => '192.0.2.9', 'ttl' => 60, 'disabled' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'data' => [
                'record' => [
                    'id' => self::RECORD_ID,
                    'zone_id' => self::ZONE_ID,
                    'name' => 'www',
                    'type' => 'A',
                    'content' => '192.0.2.9',
                    'ttl' => 60,
                    'priority' => 0,
                    'disabled' => true,
                    'auth' => true,
                    'ptr_updated' => false,
                ],
            ],
            'message' => 'Record updated successfully',
        ], $this->decode($response));
    }

    public function testTheFallbackResponseBodyMatchesTheRereadOne(): void
    {
        // The API backend re-keys a record on content change; the body is then built
        // from the submitted values and must carry the same keys in the same order
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->records->method('getRecordFromId')->willReturn(null);
        $this->records->method('getNewRecordId')->willReturn(4242);
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());

        $response = $this->update(['content' => '192.0.2.9', 'ttl' => 60, 'priority' => 5, 'disabled' => true]);

        $this->assertSame([
            'success' => true,
            'data' => [
                'record' => [
                    'id' => 4242,
                    'zone_id' => self::ZONE_ID,
                    'name' => 'www',
                    'type' => 'A',
                    'content' => '192.0.2.9',
                    'ttl' => 60,
                    'priority' => 5,
                    'disabled' => true,
                    'auth' => true,
                    'ptr_updated' => false,
                ],
            ],
            'message' => 'Record updated successfully',
        ], $this->decode($response));
    }

    public function testUpdatePtrSyncsTheReverseRecordWithTheRereadValuesAndReportsIt(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->records->method('getRecordFromId')->willReturn($this->existingRecord(['content' => '192.0.2.9', 'ttl' => 60]));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->reverseCreator->expects($this->once())->method('updateReverseRecord')
            ->with('A', '192.0.2.1', 'www.example.com', 'A', '192.0.2.9', 'www.example.com', self::ZONE_ID, 60, 0)
            ->willReturn(['success' => true, 'message' => 'PTR record updated']);

        $response = $this->update(['content' => '192.0.2.9', 'update_ptr' => true]);
        $body = $this->decode($response);

        $this->assertSame('Record updated successfully PTR record updated', $body['message']);
        $this->assertTrue($body['data']['record']['ptr_updated']);
    }

    public function testAFailedPtrSyncKeepsTheUpdateAndReportsTheFailureInTheMessage(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->reverseCreator->method('updateReverseRecord')->willReturn(['success' => false, 'message' => 'no reverse zone']);

        $response = $this->update(['content' => '192.0.2.9', 'update_ptr' => true]);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Record updated successfully PTR record update failed: no reverse zone', $body['message']);
        $this->assertFalse($body['data']['record']['ptr_updated']);
    }

    public function testAThrowingPtrSyncIsReportedTheSameWay(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->reverseCreator->method('updateReverseRecord')->willThrowException(new \RuntimeException('backend down'));

        $response = $this->update(['content' => '192.0.2.9', 'update_ptr' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Record updated successfully PTR record update failed: backend down', $this->messageOf($response));
    }

    public function testUpdatePtrOnANonAddressRecordNeverTouchesTheReverseCreator(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord(['type' => 'TXT', 'content' => '"x"']));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->reverseCreator->expects($this->never())->method('updateReverseRecord');

        $response = $this->update(['content' => 'y', 'update_ptr' => true]);

        $this->assertSame('Record updated successfully', $this->messageOf($response));
    }

    public function testWhenTheOldIdNoLongerResolvesTheNewOneIsLookedUp(): void
    {
        // The API backend re-keys a record when its content changes; returning the
        // stale id would hand the caller an identifier that 404s next request.
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->records->method('getRecordFromId')->willReturn(null);
        $this->records->expects($this->once())
            ->method('getNewRecordId')
            ->with(self::ZONE_ID, 'www.example.com', 'A', '192.0.2.9')
            ->willReturn(4242);
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());

        $record = $this->decode($this->update(['content' => '192.0.2.9']))['data']['record'];

        $this->assertSame(4242, $record['id']);
        $this->assertSame('192.0.2.9', $record['content']);
    }

    public function testTheSubmittedRecordIdSurvivesWhenNoNewIdIsFound(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->records->method('getRecordFromId')->willReturn(null);
        $this->records->method('getNewRecordId')->willReturn(null);
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());

        $record = $this->decode($this->update(['content' => '192.0.2.9']))['data']['record'];

        $this->assertSame(self::RECORD_ID, $record['id']);
    }

    public function testATxtUpdateReQuotesOnTheWayInAndUnquotesOnTheWayOut(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord(['type' => 'TXT', 'content' => '"old"']));
        $this->records->method('getRecordFromId')->willReturn($this->existingRecord(['type' => 'TXT', 'content' => '"new value"']));
        $this->recordManager->expects($this->once())
            ->method('editRecord')
            ->with($this->callback(static fn(array $data): bool => $data['content'] === '"new value"'))
            ->willReturn(RecordWriteResult::ok());

        $record = $this->decode($this->update(['content' => 'new value']))['data']['record'];

        $this->assertSame('new value', $record['content']);
    }

    public function testACommentInTheBodyIsStoredWithTheWriteAndAgainstTheRecord(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->records->method('getRecordFromId')->willReturn($this->existingRecord(['content' => '192.0.2.9']));
        $this->recordManager->expects($this->once())->method('editRecord')
            ->with($this->anything(), true, ['content' => 'Web server', 'account' => 'apiuser'])
            ->willReturn(RecordWriteResult::ok());
        $this->comments->expects($this->once())->method('updateCommentForRecord')
            ->with(self::ZONE_ID, 'www.example.com', 'A', 'Web server', self::RECORD_ID, 'apiuser');

        $response = $this->update(['content' => '192.0.2.9', 'comment' => 'Web server']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testWithoutACommentInTheBodyTheWriteCarriesNoneAndTheRecordCommentIsLeftAlone(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->records->method('getRecordFromId')->willReturn($this->existingRecord(['content' => '192.0.2.9']));
        $this->recordManager->expects($this->once())->method('editRecord')
            ->with($this->anything(), true, null)
            ->willReturn(RecordWriteResult::ok());
        $this->comments->expects($this->never())->method('updateCommentForRecord');
        $this->comments->expects($this->never())->method('updateComment');

        $this->update(['content' => '192.0.2.9']);
    }

    public function testARenameWithoutACommentCarriesTheExistingCommentAlong(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->records->method('getRecordFromId')->willReturn($this->existingRecord(['name' => 'web.example.com']));
        $this->recordManager->method('editRecord')->willReturn(RecordWriteResult::ok());
        $this->comments->method('findComment')->with(self::ZONE_ID, 'www.example.com', 'A')
            ->willReturn(RecordComment::create(self::ZONE_ID, 'www.example.com', 'A', 'keep me', 'someone'));
        $this->comments->expects($this->once())->method('updateComment')
            ->with(self::ZONE_ID, 'www.example.com', 'A', 'web.example.com', 'A', 'keep me', 'apiuser');

        $response = $this->update(['name' => 'web']);

        $this->assertSame('web', $this->decode($response)['data']['record']['name']);
    }

    public function testANonStringCommentIs400WithTheSharedMessage(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->recordManager->expects($this->never())->method('editRecord');

        $response = $this->update(['comment' => 7]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid field types in request body', $this->messageOf($response));
    }

    public function testDeletingARecordFromAnotherZoneIs404(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord(['domain_id' => 4]));
        $this->recordManager->expects($this->never())->method('deleteRecord');

        $response = $this->delete();

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeletingAProtectedRecordTypeIs403WithTheDeleteWording(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord(['type' => 'NS']));
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $this->permissions->method('canEditZoneRecord')->willReturn(false);

        $response = $this->delete();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to delete this record type', $this->messageOf($response));
    }

    public function testASuccessfulDeleteIs204WithAnEmptyBody(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::ok());

        $response = $this->delete();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string)$response->getContent());
    }

    public function testAFailedDeleteKeepsTheRefusalReasonButHidesBackendFaults(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::forbidden('SOA records cannot be deleted'));

        $response = $this->delete();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('SOA records cannot be deleted', $this->messageOf($response));
    }

    public function testABackendFaultOnDeleteIsGenericised(): void
    {
        $this->records->method('getRecordById')->willReturn($this->existingRecord());
        $this->recordManager->method('deleteRecord')->willReturn(RecordWriteResult::backendFailure('boom'));

        $response = $this->delete();

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Failed to delete record', $this->messageOf($response));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function existingRecord(array $overrides = []): array
    {
        return array_merge([
            'id' => self::RECORD_ID,
            'domain_id' => self::ZONE_ID,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.1',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function update(array $body): JsonResponse
    {
        return $this->updateRaw((string)json_encode($body));
    }

    private function updateRaw(string $rawBody): JsonResponse
    {
        return $this->invokeHandler('updateRecord', 'PUT', $rawBody);
    }

    private function delete(): JsonResponse
    {
        return $this->invokeHandler('deleteRecord', 'DELETE', '');
    }

    private function invokeHandler(string $handler, string $method, string $rawBody): JsonResponse
    {
        $controller = $this->bareController(ZonesRecordsController::class);
        $this->injectBaseCollaborators($controller, $method);
        $this->inject($controller, 'request', Request::create(
            '/api/v2/zones/' . self::ZONE_ID . '/records/' . self::RECORD_ID,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody
        ));

        $this->zones->method('getZoneById')->willReturn($this->zoneRow);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn(self::ZONE_NAME);

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('domainRepository')->willReturn($domains);
        $factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $factory->method('reverseRecordCreator')->willReturn($this->reverseCreator);
        $factory->method('userRepository')->willReturn($this->stubUsers());
        // The real edit flow over the same doubles, so the PUT is characterized end to end
        $factory->method('recordEditService')->willReturn(new RecordEditService(
            $this->recordManager,
            $this->records,
            $domains,
            $this->createMock(SOARecordManagerInterface::class),
            $this->reverseCreator,
            $this->comments,
            $this->commentSync,
            $this->createMock(AuditService::class),
            $this->createMock(ConfigurationInterface::class),
            new NullLogger()
        ));

        $ttlResolver = $this->createMock(ReverseTtlResolver::class);
        $ttlResolver->method('resolveTtlForType')->willReturn(3600);

        $this->inject($controller, 'serviceFactory', $factory);
        $this->inject($controller, 'zoneRepository', $this->zones);
        $this->inject($controller, 'recordRepository', $this->records);
        $this->inject($controller, 'recordManager', $this->recordManager);
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'reverseTtlResolver', $ttlResolver);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'apiKeyScope', $this->scope);
        $this->inject($controller, 'pathParameters', ['id' => self::ZONE_ID, 'record_id' => $this->recordIdParameter]);

        return $this->callHandler($controller, $handler);
    }
}
