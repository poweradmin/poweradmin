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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\EditRecordController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Service\UserPreferenceService;
use Poweradmin\Domain\Service\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\ZoneChangeRequestService;

/**
 * Characterizes the edit-record page: the order in which it refuses a request,
 * how a save is normalised before it reaches the record manager, what each
 * outcome flashes, and when the change-request path takes over.
 */
#[CoversClass(EditRecordController::class)]
class EditRecordControllerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;
    private const RECORD_ID = 34;

    private string $editLevel = 'all';
    private string $changeRequestLevel = 'none';
    private bool $ownsZone = true;
    private bool $canViewOthers = true;
    private bool $canPerformZoneAction = true;
    private string $zoneType = 'MASTER';
    private ?string $zoneName = 'example.com';
    private int $zoneIdOfRecord = self::ZONE_ID;

    /** @var array<string, mixed>|null */
    private ?array $storedRecord = [
        'id' => self::RECORD_ID,
        'name' => 'www.example.com',
        'type' => 'A',
        'content' => '192.0.2.1',
        'ttl' => 3600,
        'prio' => 0,
    ];

    private RecordWriteResult $editResult;

    /** @var array<string, mixed>|null The row handed to RecordManager::editRecord() */
    private ?array $editedRecord = null;

    private ZoneChangeRequestResult $fileResult;

    /** @var array{success: bool, message?: string} */
    private array $reverseResult = ['success' => true];

    /** @var PermissionService&MockObject */
    private PermissionService $permissions;

    /** @var RecordRepositoryInterface&MockObject */
    private RecordRepositoryInterface $records;

    /** @var DomainRepositoryInterface&MockObject */
    private DomainRepositoryInterface $domains;

    /** @var SOARecordManagerInterface&MockObject */
    private SOARecordManagerInterface $soa;

    /** @var ReverseRecordCreator&MockObject */
    private ReverseRecordCreator $reverseCreator;

    /** @var RecordCommentService&MockObject */
    private RecordCommentService $comments;

    /** @var RecordCommentSyncService&MockObject */
    private RecordCommentSyncService $commentSync;

    /** @var ZoneChangeRequestService&MockObject */
    private ZoneChangeRequestService $changeRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editResult = RecordWriteResult::ok();
        $this->fileResult = ZoneChangeRequestResult::ok(9, 'filed');

        $this->permissions = $this->createMock(PermissionService::class);
        $this->permissions->method('hasPermission')->willReturnCallback(fn(): bool => $this->canViewOthers);
        $this->permissions->method('canPerformZoneAction')->willReturnCallback(fn(): bool => $this->canPerformZoneAction);
        $this->permissions->method('userOwnsZone')->willReturnCallback(fn(): bool => $this->ownsZone);
        $this->permissions->method('getEditPermissionLevelForZone')->willReturnCallback(fn(): string => $this->editLevel);
        $this->permissions->method('getChangeRequestPermissionLevelForZone')
            ->willReturnCallback(fn(): string => $this->changeRequestLevel);

        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('getZoneIdFromRecordId')->willReturnCallback(fn(): int => $this->zoneIdOfRecord);
        $this->records->method('getRecordFromId')->willReturnCallback(fn(): ?array => $this->storedRecord);

        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('getDomainType')->willReturnCallback(fn(): string => $this->zoneType);
        $this->domains->method('getDomainNameById')->willReturnCallback(fn(): ?string => $this->zoneName);

        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('editRecord')->willReturnCallback(function (array $record): RecordWriteResult {
            $this->editedRecord = $record;
            return $this->editResult;
        });

        $this->soa = $this->createMock(SOARecordManagerInterface::class);
        $this->reverseCreator = $this->createMock(ReverseRecordCreator::class);
        $this->reverseCreator->method('updateReverseRecord')->willReturnCallback(fn(): array => $this->reverseResult);

        $this->comments = $this->createMock(RecordCommentService::class);
        $this->commentSync = $this->createMock(RecordCommentSyncService::class);

        $this->changeRequests = $this->createMock(ZoneChangeRequestService::class);
        $this->changeRequests->method('fileRecordEdits')->willReturnCallback(fn(): ZoneChangeRequestResult => $this->fileResult);

        $preferences = $this->createMock(UserPreferenceService::class);
        $preferences->method('getDisplayHostnameOnly')->willReturn(false);

        $this->factory->method('permissionService')->willReturn($this->permissions);
        $this->factory->method('recordRepository')->willReturn($this->records);
        $this->factory->method('domainRepository')->willReturn($this->domains);
        $this->factory->method('recordManager')->willReturn($recordManager);
        $this->factory->method('soaRecordManager')->willReturn($this->soa);
        $this->factory->method('reverseRecordCreator')->willReturn($this->reverseCreator);
        $this->factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $this->factory->method('userPreferenceService')->willReturn($preferences);
        $this->factory->method('zoneChangeRequestService')->willReturn($this->changeRequests);
    }

    /** @param array<string, array<string, mixed>> $config */
    private function makeController(array $config = [], ?string $recordId = null): TestableEditRecordController
    {
        return new TestableEditRecordController(
            ['id' => $recordId ?? (string)self::RECORD_ID] + array_merge($_GET, $_POST),
            $this->environment($this->configure($config)),
            $this->comments,
            $this->commentSync
        );
    }

    private function haltOf(TestableEditRecordController $controller): ControllerHalt
    {
        try {
            $controller->run();
        } catch (ControllerHalt $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }

    // ---------------------------------------------------------------- gates

    /** @return array<string, array{0: string}> */
    public static function badRecordIdProvider(): array
    {
        return [
            'empty' => [''],
            'zero is falsy' => ['0'],
            'not a number and not an encoded id' => ['abc'],
        ];
    }

    #[DataProvider('badRecordIdProvider')]
    public function testAnUnusableRecordIdIsRefusedBeforeAnyLookup(string $recordId): void
    {
        $this->records->expects($this->never())->method('getZoneIdFromRecordId');

        $halt = $this->haltOf($this->makeController([], $recordId));

        $this->assertSame(ControllerHalt::KIND_ERROR, $halt->kind);
        $this->assertSame('Invalid record ID.', $halt->target);
    }

    public function testARecordWithoutAZoneIsRefusedWithTheSameMessage(): void
    {
        // The repository declares a non-nullable int, so the controller's
        // "no zone" branch is only ever reached with a zero id.
        $this->zoneIdOfRecord = 0;

        $this->assertSame('Invalid record ID.', $this->haltOf($this->makeController())->target);
    }

    public function testViewingNeedsEitherTheZoneOrTheViewOthersPermission(): void
    {
        $this->canViewOthers = false;
        $this->canPerformZoneAction = false;

        $halt = $this->haltOf($this->makeController());

        $this->assertSame('You do not have permission to view this record.', $halt->target);
    }

    public function testZoneScopedViewPermissionIsEnoughWithoutViewOthers(): void
    {
        $this->canViewOthers = false;
        $this->canPerformZoneAction = true;

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame('edit_record.html', $controller->rendered[0][0]);
    }

    public function testAReadOnlyZoneIsRefusedBeforeTheEditPermissionIsResolved(): void
    {
        $this->zoneType = 'SLAVE';
        $this->editLevel = 'none';

        $halt = $this->haltOf($this->makeController());

        $this->assertSame('You cannot edit records in a read-only zone.', $halt->target);
    }

    public function testWithoutAnyEditLevelTheRecordCannotBeOpened(): void
    {
        $this->editLevel = 'none';

        $halt = $this->haltOf($this->makeController());

        $this->assertSame('You do not have permission to edit this record.', $halt->target);
    }

    public function testARequesterGetsTheEditorFormInRequestMode(): void
    {
        // No edit rights, but a change-request level: the form opens and the
        // save files a request instead of writing.
        $this->editLevel = 'none';
        $this->changeRequestLevel = 'all';

        $controller = $this->makeController(['approval' => ['enabled' => true]]);
        $controller->run();
        $params = $controller->renderedParams();

        $this->assertSame(ChangeApprovalPolicy::MODE_REQUEST, $params['edit_mode']);
        $this->assertSame('all', $params['perm_edit'], 'the change-request level stands in for the edit level');
        // Because the change-request level is passed on as perm_edit, the
        // template is told the zone is editable even in request mode. As-is.
        $this->assertTrue($params['zone_is_editable']);
    }

    public function testAMissingRecordRowIsReportedByTheForm(): void
    {
        $this->storedRecord = null;

        $this->assertSame('Record not found.', $this->haltOf($this->makeController())->target);
    }

    // ------------------------------------------------------------ direct save

    public function testASuccessfulSaveFlashesAndRedirectsToTheZone(): void
    {
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.9', 'ttl' => '300']);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(ControllerHalt::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/zones/12/edit', $halt->target);
        $this->assertSame([['success', 'The record has been updated successfully.']], $this->messagesFor('edit'));
    }

    public function testTheSavedRowIsNormalisedBeforeItReachesTheRecordManager(): void
    {
        $this->post([
            'rid' => (string)self::RECORD_ID,
            'name' => 'wWw',
            'type' => 'A',
            'content' => '192.0.2.9',
            'ttl' => '300',
        ]);

        $this->haltOf($this->makeController());

        // toPunycode() also lowercases, so the submitted casing is not kept
        $this->assertSame('www.example.com', $this->editedRecord['name'], 'the zone suffix is restored');
        $this->assertSame(0, $this->editedRecord['disabled'], 'an absent checkbox means enabled');
    }

    public function testAnIdnRecordNameIsConvertedToPunycode(): void
    {
        $this->zoneName = 'exämple.com';
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'wüw.exämple.com', 'type' => 'A', 'content' => '192.0.2.9']);

        $this->haltOf($this->makeController());

        $this->assertStringStartsWith('xn--', $this->editedRecord['name']);
    }

    public function testTheDisabledCheckboxBecomesAFlag(): void
    {
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.9', 'disabled' => 'on']);

        $this->haltOf($this->makeController());

        $this->assertSame(1, $this->editedRecord['disabled']);
    }

    public function testARefusedWriteKeepsTheUserOnTheFormWithoutARedirect(): void
    {
        $this->editResult = RecordWriteResult::failure('Invalid IPv4 address.');
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'www', 'type' => 'A', 'content' => 'nope']);

        $controller = $this->makeController();
        $controller->run();

        $this->assertNull($controller->redirectedTo);
        $this->assertSame('edit_record.html', $controller->rendered[0][0]);
        $this->assertSame([['error', 'Invalid IPv4 address.']], $this->messagesFor('system'));
    }

    public function testAMissingRowStopsTheSaveWithAMessageAndStillRendersTheForm(): void
    {
        $this->storedRecord = null;
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.9']);

        // The save reports "Record not found." first, then the form does too
        $halt = $this->haltOf($this->makeController());

        $this->assertSame('Record not found.', $halt->target);
        $this->assertSame([['error', 'Record not found.']], $this->messagesFor('edit'));
    }

    public function testAMissingZoneNameStopsTheSaveButThenBreaksTheForm(): void
    {
        // saveRecord() reports "Zone not found." and returns false, but the
        // form it falls through to hands the same null zone name to
        // DnsIdnService::toDisplay(), which only accepts a string.
        $this->zoneName = null;
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.9']);

        $controller = $this->makeController();

        try {
            $controller->run();
            $this->fail('Expected the render of the form to fail on the null zone name.');
        } catch (\TypeError $error) {
            $this->assertStringContainsString('toDisplay', $error->getMessage());
        }

        $this->assertNull($controller->redirectedTo);
        $this->assertSame([['error', 'Zone not found.']], $this->messagesFor('edit'));
    }

    // -------------------------------------------------------------- SOA serial

    public function testEditingAnSoaBumpsTheSerial(): void
    {
        $this->storedRecord = [
            'id' => self::RECORD_ID,
            'name' => 'example.com',
            'type' => 'SOA',
            'content' => 'ns1.example.com hostmaster.example.com 2026010101 10800 3600 604800 3600',
            'ttl' => 3600,
            'prio' => 0,
        ];
        $this->soa->expects($this->once())->method('updateSOASerial')->with(self::ZONE_ID);
        $this->post([
            'rid' => (string)self::RECORD_ID,
            'name' => 'example.com',
            'type' => 'SOA',
            'content' => 'ns1.example.com hostmaster.example.com [SERIAL] 10800 3600 604800 3600',
            'ttl' => '3600',
        ]);

        $this->haltOf($this->makeController());

        $this->assertStringContainsString(
            '2026010101',
            $this->editedRecord['content'],
            'the [SERIAL] placeholder is expanded from the stored value'
        );
    }

    public function testANonSoaEditNeverBumpsTheSerialItself(): void
    {
        $this->soa->expects($this->never())->method('updateSOASerial');
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.9']);

        $this->haltOf($this->makeController());
    }

    // ------------------------------------------------------------ PTR syncing

    public function testThePtrIsLeftAloneUnlessTheBoxIsTicked(): void
    {
        $this->reverseCreator->expects($this->never())->method('updateReverseRecord');
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.9']);

        $this->haltOf($this->makeController());
    }

    public function testTickingUpdatePtrOnANonAddressRecordDoesNothing(): void
    {
        $this->storedRecord = [
            'id' => self::RECORD_ID,
            'name' => 'www.example.com',
            'type' => 'TXT',
            'content' => 'hello',
            'ttl' => 3600,
            'prio' => 0,
        ];
        $this->reverseCreator->expects($this->never())->method('updateReverseRecord');
        $this->post([
            'rid' => (string)self::RECORD_ID,
            'name' => 'www',
            'type' => 'TXT',
            'content' => 'bye',
            'update_ptr' => '1',
        ]);

        $this->haltOf($this->makeController());
    }

    public function testAFailedPtrUpdateDowngradesTheOutcomeToAWarning(): void
    {
        $this->reverseResult = ['success' => false, 'message' => 'no reverse zone'];
        $this->post([
            'rid' => (string)self::RECORD_ID,
            'name' => 'www',
            'type' => 'A',
            'content' => '192.0.2.9',
            'update_ptr' => '1',
        ]);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame('/zones/12/edit', $halt->target, 'the record itself was still saved');
        $this->assertSame([
            ['warning', 'The record was updated, but the PTR record could not be updated: no reverse zone'],
            ['success', 'The record has been updated successfully.'],
        ], $this->messagesFor('edit'));
    }

    public function testATypeChangeAwayFromAnAddressStillSyncsThePtr(): void
    {
        $this->reverseCreator->expects($this->once())->method('updateReverseRecord')
            ->with('A', '192.0.2.1', 'www.example.com', 'TXT');
        $this->post([
            'rid' => (string)self::RECORD_ID,
            'name' => 'www',
            'type' => 'TXT',
            'content' => 'hello',
            'update_ptr' => '1',
        ]);

        $this->haltOf($this->makeController());
    }

    // ------------------------------------------------------------- comments

    public function testWithCommentsHiddenARenameMigratesTheExistingComment(): void
    {
        $this->storedRecord = [
            'id' => self::RECORD_ID,
            'name' => 'old.example.com',
            'type' => 'A',
            'content' => '192.0.2.1',
            'ttl' => 3600,
            'prio' => 0,
        ];
        // getRecordFromId() answers the pre-edit row first and the saved row after
        $rows = [$this->storedRecord, ['name' => 'new.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0]];
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('getZoneIdFromRecordId')->willReturn(self::ZONE_ID);
        $this->records->method('getRecordFromId')->willReturnCallback(
            function () use (&$rows): array {
                return count($rows) > 1 ? array_shift($rows) : $rows[0];
            }
        );
        $this->factory = $this->createMock(\Poweradmin\Application\Service\ControllerServiceFactory::class);
        $this->rewireFactory();

        $this->comments->expects($this->once())->method('findComment')
            ->with(self::ZONE_ID, 'old.example.com', 'A')
            ->willReturn(null);
        $this->comments->expects($this->never())->method('updateCommentForRecord');

        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'new', 'type' => 'A', 'content' => '192.0.2.1']);

        $this->haltOf($this->makeController());
    }

    public function testWithCommentsVisibleTheCommentIsWrittenPerRecord(): void
    {
        $this->comments->expects($this->once())->method('updateCommentForRecord')
            ->with(self::ZONE_ID, 'www.example.com', 'A', 'a note', self::RECORD_ID, self::USERNAME);
        $this->post([
            'rid' => (string)self::RECORD_ID,
            'name' => 'www',
            'type' => 'A',
            'content' => '192.0.2.1',
            'comment' => 'a note',
        ]);

        $this->haltOf($this->makeController(['interface' => ['show_record_comments' => true]]));
    }

    public function testTheFormShowsTheLegacyRrsetCommentWhenNoPerRecordOneExists(): void
    {
        $this->comments->method('findCommentByRecordId')->willReturn(null);
        $this->comments->expects($this->once())->method('findComment')
            ->with(self::ZONE_ID, 'www.example.com', 'A')
            ->willReturn(null);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame('', $controller->renderedParams()['comment']);
    }

    // --------------------------------------------------------- request mode

    public function testInRequestModeThePostFilesAChangeRequest(): void
    {
        $this->editLevel = 'none';
        $this->changeRequestLevel = 'all';
        $this->changeRequests->expects($this->once())->method('fileRecordEdits')
            ->willReturnCallback(fn(): ZoneChangeRequestResult => $this->fileResult);
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.9']);

        $halt = $this->haltOf($this->makeController(['approval' => ['enabled' => true]]));

        $this->assertSame('/zones/12/edit', $halt->target);
        $this->assertNotSame([], $this->messagesFor('edit'));
    }

    public function testARefusedFilingKeepsTheFormAndReportsEveryRowError(): void
    {
        $this->editLevel = 'none';
        $this->changeRequestLevel = 'all';
        $this->fileResult = ZoneChangeRequestResult::failure(
            ZoneChangeRequestResult::CODE_VALIDATION,
            'refused',
            400,
            ['content is invalid']
        );
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'www', 'type' => 'A', 'content' => 'nope']);

        $controller = $this->makeController(['approval' => ['enabled' => true]]);
        $controller->run();

        $this->assertNull($controller->redirectedTo);
        $this->assertSame([['error', 'content is invalid']], $this->messagesFor('system'));
        $this->assertSame([['error', 'refused']], $this->messagesFor('edit_record'));
    }

    public function testFilingNothingIsReportedAsInformationNotAnError(): void
    {
        $this->editLevel = 'none';
        $this->changeRequestLevel = 'all';
        $this->fileResult = ZoneChangeRequestResult::failure(
            ZoneChangeRequestResult::CODE_NO_CHANGES,
            'nothing changed'
        );
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.1']);

        $controller = $this->makeController(['approval' => ['enabled' => true]]);
        $controller->run();

        $this->assertSame(
            [['info', 'Nothing differs from the zone, so no request was filed.']],
            $this->messagesFor('edit_record')
        );
    }

    public function testAUserWhoMayEditDirectlyNeverEntersRequestMode(): void
    {
        $this->changeRequestLevel = 'all';
        $this->changeRequests->expects($this->never())->method('fileRecordEdits');
        $this->post(['rid' => (string)self::RECORD_ID, 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.9']);

        $this->haltOf($this->makeController(['approval' => ['enabled' => true]]));
    }

    private function rewireFactory(): void
    {
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('editRecord')->willReturnCallback(function (array $record): RecordWriteResult {
            $this->editedRecord = $record;
            return $this->editResult;
        });
        $preferences = $this->createMock(UserPreferenceService::class);
        $preferences->method('getDisplayHostnameOnly')->willReturn(false);

        $this->factory->method('permissionService')->willReturn($this->permissions);
        $this->factory->method('recordRepository')->willReturn($this->records);
        $this->factory->method('domainRepository')->willReturn($this->domains);
        $this->factory->method('recordManager')->willReturn($recordManager);
        $this->factory->method('soaRecordManager')->willReturn($this->soa);
        $this->factory->method('reverseRecordCreator')->willReturn($this->reverseCreator);
        $this->factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $this->factory->method('userPreferenceService')->willReturn($preferences);
        $this->factory->method('zoneChangeRequestService')->willReturn($this->changeRequests);
    }
}
