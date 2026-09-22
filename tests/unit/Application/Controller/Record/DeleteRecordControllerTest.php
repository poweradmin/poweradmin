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

namespace Poweradmin\Tests\Unit\Application\Controller\Record;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Record\DeleteRecordController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Dns\RecordDeletionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestService;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Characterizes the delete-record page: the gates in front of the deletion,
 * which of the six success messages a deletion flashes depending on the
 * record type, the reverse-record setting and what was found on the other
 * side, and the two failure paths.
 */
#[CoversClass(DeleteRecordController::class)]
class DeleteRecordControllerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;
    private const RECORD_ID = 34;

    private string $editLevel = 'all';
    private string $viewLevel = 'all';
    private string $changeRequestLevel = 'none';
    private bool $ownsZone = true;
    private string $zoneType = 'MASTER';
    private int $zoneIdOfRecord = self::ZONE_ID;
    private bool $ptrDeleted = false;
    private bool $forwardDeleted = false;

    /** @var array<string, mixed>|null */
    private ?array $storedRecord = [
        'id' => self::RECORD_ID,
        'name' => 'www.example.com',
        'type' => 'A',
        'content' => '192.0.2.1',
        'ttl' => 3600,
        'prio' => 0,
    ];

    private RecordWriteResult $deleteResult;
    private ZoneChangeRequestResult $fileResult;

    /** @var RecordRepositoryInterface&MockObject */
    private RecordRepositoryInterface $records;

    /** @var DomainRepositoryInterface&MockObject */
    private DomainRepositoryInterface $domains;

    /** @var RecordManagerInterface&MockObject */
    private RecordManagerInterface $recordManager;

    /** @var ReverseRecordCreator&MockObject */
    private ReverseRecordCreator $reverseCreator;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    /** @var ZoneChangeRequestService&MockObject */
    private ZoneChangeRequestService $changeRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteResult = RecordWriteResult::ok();
        $this->fileResult = ZoneChangeRequestResult::ok(9, 'filed');

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('userOwnsZone')->willReturnCallback(fn(): bool => $this->ownsZone);
        $permissions->method('getEditPermissionLevelForZone')->willReturnCallback(fn(): string => $this->editLevel);
        $permissions->method('getViewPermissionLevel')->willReturnCallback(fn(): string => $this->viewLevel);
        $permissions->method('getChangeRequestPermissionLevelForZone')
            ->willReturnCallback(fn(): string => $this->changeRequestLevel);

        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('getZoneIdFromRecordId')->willReturnCallback(fn(): int => $this->zoneIdOfRecord);
        $this->records->method('recidToDomid')->willReturn(self::ZONE_ID);
        $this->records->method('getRecordFromId')->willReturnCallback(fn(): ?array => $this->storedRecord);

        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('getZoneInfoFromId')->willReturnCallback(fn(): array => ['type' => $this->zoneType]);
        $this->domains->method('getDomainNameById')->willReturn('example.com');

        $this->recordManager = $this->createMock(RecordManagerInterface::class);
        $this->recordManager->method('deleteRecord')->willReturnCallback(fn(): RecordWriteResult => $this->deleteResult);

        $this->reverseCreator = $this->createMock(ReverseRecordCreator::class);
        $this->reverseCreator->method('deleteReverseRecord')->willReturnCallback(fn(): bool => $this->ptrDeleted);
        $this->reverseCreator->method('deleteForwardRecord')->willReturnCallback(fn(): bool => $this->forwardDeleted);

        $this->audit = $this->createMock(AuditService::class);

        $this->changeRequests = $this->createMock(ZoneChangeRequestService::class);
        $this->changeRequests->method('fileRecordDelete')->willReturnCallback(fn(): ZoneChangeRequestResult => $this->fileResult);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('recordRepository')->willReturn($this->records);
        $this->factory->method('domainRepository')->willReturn($this->domains);
        $this->factory->method('recordManager')->willReturn($this->recordManager);
        $this->factory->method('reverseRecordCreator')->willReturn($this->reverseCreator);
        $this->factory->method('auditService')->willReturn($this->audit);
        $this->factory->method('zoneChangeRequestService')->willReturn($this->changeRequests);
        $this->factory->method('recordDeletionService')->willReturnCallback(fn(): RecordDeletionService => new RecordDeletionService(
            $this->records,
            $this->recordManager,
            $this->reverseCreator,
            $this->audit,
            (bool)$this->config->get('interface', 'add_reverse_record', false)
        ));
    }

    /** @param array<string, array<string, mixed>> $config */
    private function makeController(array $config = [], ?string $recordId = null): DeleteRecordController
    {
        return new DeleteRecordController(
            ['id' => $recordId ?? (string)self::RECORD_ID] + $this->requestData(),
            true,
            $this->environment($this->configure($config))
        );
    }

    private function haltOf(DeleteRecordController $controller): RequestHalted
    {
        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }

    private function record(string $type, string $name, string $content): void
    {
        $this->storedRecord = ['id' => self::RECORD_ID, 'name' => $name, 'type' => $type, 'content' => $content, 'ttl' => 3600, 'prio' => 0];
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

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame('Invalid or unexpected input given.', $halt->target);
    }

    public function testARecordWithoutAZoneIsRefused(): void
    {
        $this->zoneIdOfRecord = 0;

        $this->assertSame('Invalid record ID.', $this->haltOf($this->makeController())->target);
    }

    public function testWithoutAnyEditLevelTheDeletionIsRefused(): void
    {
        $this->editLevel = 'none';

        $halt = $this->haltOf($this->makeController());

        $this->assertSame('You do not have permission to delete records in this zone.', $halt->target);
    }

    public function testAReadOnlyZoneIsRefusedOnTheConfirmationPage(): void
    {
        $this->zoneType = 'SLAVE';

        $halt = $this->haltOf($this->makeController());

        $this->assertSame('You cannot delete records from a read-only zone.', $halt->target);
    }

    public function testWithoutAnyViewLevelTheZoneIsNotLookedUpAndTheRefusalIsFlashed(): void
    {
        $this->viewLevel = 'none';
        $this->domains->expects($this->never())->method('getZoneInfoFromId');

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'You do not have permission to view this zone.']], $this->messagesFor('system'));
        $this->assertSame('delete_record.html', $this->output->rendered[0][0]);
    }

    public function testAGetRendersTheConfirmationPage(): void
    {
        $controller = $this->makeController();
        $controller->run();

        $this->assertSame('delete_record.html', $this->output->rendered[0][0]);
        $params = $this->output->rendered[0][1];
        $this->assertSame((string)self::RECORD_ID, $params['record_id']);
        $this->assertSame(self::ZONE_ID, $params['zone_id']);
        $this->assertSame(ChangeApprovalPolicy::MODE_DIRECT, $params['edit_mode']);
        $this->assertSame('www.example.com', $params['record_info']['display_name']);
    }

    // ------------------------------------------------------------ direct delete

    public function testAMissingRecordRowIsReportedBeforeDeleting(): void
    {
        $this->post([]);
        $this->storedRecord = null;
        $this->recordManager->expects($this->never())->method('deleteRecord');

        $this->assertSame('Record not found.', $this->haltOf($this->makeController())->target);
    }

    public function testAFailedDeletionIsFlashedAsASystemErrorAndTheQuestionIsShownAgain(): void
    {
        $this->post([]);
        $this->deleteResult = RecordWriteResult::backendFailure('Backend refused');
        $this->audit->expects($this->never())->method('logRecordDelete');

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'Backend refused']], $this->messagesFor('system'));
        $this->assertSame('delete_record.html', $this->output->rendered[0][0]);
    }

    public function testASuccessfulDeletionIsAuditedAndRedirectsToTheZone(): void
    {
        $this->post([]);
        $this->audit->expects($this->once())->method('logRecordDelete')
            ->with(self::ZONE_ID, 'A', 'www.example.com', '192.0.2.1', 3600, 0);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/zones/12/edit', $halt->target);
    }

    /**
     * The six outcomes of the deletion, keyed by the record type, the
     * add_reverse_record setting, the ticked boxes and what the other side
     * reported.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function messageProvider(): array
    {
        return [
            'plain record with reverse handling off' => [
                ['type' => 'A', 'reverse' => false, 'post' => []],
                'The record has been deleted successfully.',
            ],
            'MX record with reverse handling on' => [
                ['type' => 'MX', 'reverse' => true, 'post' => ['delete_ptr' => '1', 'delete_forward' => '1']],
                'The record has been deleted successfully.',
            ],
            'A record, PTR box ticked, PTR found and deleted' => [
                ['type' => 'A', 'reverse' => true, 'post' => ['delete_ptr' => '1'], 'ptr' => true],
                'The record and its corresponding PTR record have been deleted successfully.',
            ],
            'AAAA record, PTR box ticked, no PTR on the other side' => [
                ['type' => 'AAAA', 'reverse' => true, 'post' => ['delete_ptr' => '1'], 'ptr' => false],
                'The record has been deleted successfully. No matching PTR record was found.',
            ],
            // The "no matching PTR" wording is also used when the box was never ticked. As-is.
            'A record, PTR box not ticked' => [
                ['type' => 'A', 'reverse' => true, 'post' => []],
                'The record has been deleted successfully. No matching PTR record was found.',
            ],
            'PTR record, forward box ticked, A record found and deleted' => [
                ['type' => 'PTR', 'reverse' => true, 'post' => ['delete_forward' => '1'], 'forward' => true],
                'The record and its corresponding A/AAAA record have been deleted successfully.',
            ],
            'PTR record, forward box ticked, nothing on the other side' => [
                ['type' => 'PTR', 'reverse' => true, 'post' => ['delete_forward' => '1'], 'forward' => false],
                'The record has been deleted successfully. No matching A/AAAA record was found.',
            ],
            'PTR record, forward box not ticked' => [
                ['type' => 'PTR', 'reverse' => true, 'post' => []],
                'The record has been deleted successfully. No matching A/AAAA record was found.',
            ],
        ];
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('messageProvider')]
    public function testTheSuccessMessageNamesWhatWasDeleted(array $case, string $expected): void
    {
        $this->record($case['type'], $case['type'] === 'PTR' ? '1.2.0.192.in-addr.arpa' : 'www.example.com', $case['type'] === 'PTR' ? 'www.example.com' : '192.0.2.1');
        $this->ptrDeleted = $case['ptr'] ?? false;
        $this->forwardDeleted = $case['forward'] ?? false;
        $this->post($case['post']);

        $halt = $this->haltOf($this->makeController(['interface' => ['add_reverse_record' => $case['reverse']]]));

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame([['success', $expected]], $this->messagesFor('edit'));
    }

    public function testThePtrIsOnlyRemovedWhenTheBoxIsTicked(): void
    {
        $this->reverseCreator->expects($this->never())->method('deleteReverseRecord');
        $this->post([]);

        $this->haltOf($this->makeController(['interface' => ['add_reverse_record' => true]]));
    }

    public function testThePtrIsRemovedWithTheDeletedRecordsTypeContentAndName(): void
    {
        $this->reverseCreator->expects($this->once())->method('deleteReverseRecord')
            ->with('A', '192.0.2.1', 'www.example.com')->willReturn(true);
        $this->post(['delete_ptr' => '1']);

        $this->haltOf($this->makeController(['interface' => ['add_reverse_record' => true]]));
    }

    public function testTheForwardRecordIsRemovedWithThePtrNameAndContent(): void
    {
        $this->record('PTR', '1.2.0.192.in-addr.arpa', 'www.example.com');
        $this->reverseCreator->expects($this->once())->method('deleteForwardRecord')
            ->with('1.2.0.192.in-addr.arpa', 'www.example.com')->willReturn(true);
        $this->post(['delete_forward' => '1']);

        $this->haltOf($this->makeController(['interface' => ['add_reverse_record' => true]]));
    }

    public function testWithReverseHandlingOffTheOtherSideIsNeverTouched(): void
    {
        $this->reverseCreator->expects($this->never())->method('deleteReverseRecord');
        $this->reverseCreator->expects($this->never())->method('deleteForwardRecord');
        $this->post(['delete_ptr' => '1', 'delete_forward' => '1']);

        $this->haltOf($this->makeController());
    }

    // ---------------------------------------------------------- request mode

    public function testInRequestModeAPostFilesAChangeRequestInsteadOfDeleting(): void
    {
        $this->editLevel = 'none';
        $this->changeRequestLevel = 'all';
        $this->recordManager->expects($this->never())->method('deleteRecord');
        $this->changeRequests->expects($this->once())->method('fileRecordDelete')
            ->with(self::ZONE_ID, self::RECORD_ID, self::USER_ID, self::USERNAME, 'please')
            ->willReturn($this->fileResult);
        $this->post(['request_comment' => ' please ']);

        $halt = $this->haltOf($this->makeController(['approval' => ['enabled' => true]]));

        $this->assertSame('/zones/12/edit', $halt->target);
        $this->assertCount(1, $this->messagesFor('edit'));
    }

    public function testARefusedChangeRequestIsFlashedAndTheQuestionIsShownAgain(): void
    {
        $this->editLevel = 'none';
        $this->changeRequestLevel = 'all';
        $this->fileResult = ZoneChangeRequestResult::failure('custom', 'nope');
        $this->post([]);

        $controller = $this->makeController(['approval' => ['enabled' => true]]);
        $controller->run();

        $this->assertSame([['error', 'nope']], $this->messagesFor('system'));
        $this->assertSame(ChangeApprovalPolicy::MODE_REQUEST, $this->output->rendered[0][1]['edit_mode']);
    }
}
