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
use Poweradmin\Application\Controller\Record\AddRecordController;
use Poweradmin\Application\Service\Zone\ChangeApprovalContext;
use Poweradmin\Application\Service\Record\RecordAddResult;
use Poweradmin\Application\Service\Record\RecordAddService;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\DomainRecordCreator;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Domain\Service\User\UserPreferenceService;
use Poweradmin\Domain\Service\Validation\RecordField;
use Poweradmin\Infrastructure\Session\FormStateService;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Characterizes the add-record page: the order of its gates, what a refused
 * write leaves behind for the form, and how the multi-record path reports a
 * partial success.
 */
#[CoversClass(AddRecordController::class)]
class AddRecordControllerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;

    private string $editLevel = 'all';
    private bool $ownsZone = true;
    private string $zoneType = 'MASTER';
    private ?string $zoneName = 'example.com';

    /** @var list<RecordAddResult> Answers handed out by RecordAddService::add(), in order */
    private array $addResults = [];

    /** @var list<array<int, mixed>> Arguments each add() call was made with */
    private array $addCalls = [];

    /** @var PermissionService&MockObject */
    private PermissionService $permissions;

    /** @var RecordAddService&MockObject */
    private RecordAddService $recordAdd;

    /** @var DomainRepositoryInterface&MockObject */
    private DomainRepositoryInterface $domains;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permissions = $this->createMock(PermissionService::class);
        $this->permissions->method('hasPermission')->willReturn(true);
        $this->permissions->method('getEditPermissionLevel')->willReturnCallback(fn(): string => $this->editLevel);
        $this->permissions->method('getEditPermissionLevelForZone')->willReturnCallback(fn(): string => $this->editLevel);
        $this->permissions->method('getChangeRequestPermissionLevelForZone')->willReturn('none');
        $this->permissions->method('userOwnsZone')->willReturnCallback(fn(): bool => $this->ownsZone);
        $this->permissions->method('canEditZoneContent')->willReturnCallback(
            fn(): bool => $this->zoneType === 'MASTER' && ($this->editLevel === 'all' || ($this->editLevel !== 'none' && $this->ownsZone))
        );

        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('getDomainType')->willReturnCallback(fn(): string => $this->zoneType);
        $this->domains->method('getDomainNameById')->willReturnCallback(fn(): ?string => $this->zoneName);

        $ttl = $this->createMock(ReverseTtlResolver::class);
        $ttl->method('getForwardTtl')->willReturn(3600);
        $ttl->method('getConfiguredReverseTtl')->willReturn(86400);
        $ttl->method('getTypeDefaults')->willReturn([]);
        $ttl->method('resolveTtlsForTypes')->willReturn([]);

        $preferences = $this->createMock(UserPreferenceService::class);
        $preferences->method('getDisplayHostnameOnly')->willReturn(false);

        $this->recordAdd = $this->recordAddOver($this->permissions, $ttl);

        $this->factory->method('permissionService')->willReturn($this->permissions);
        $this->factory->method('domainRepository')->willReturn($this->domains);
        $this->factory->method('recordAddService')->willReturn($this->recordAdd);
        $this->factory->method('reverseTtlResolver')->willReturn($ttl);
        $this->factory->method('userPreferenceService')->willReturn($preferences);
    }

    /**
     * The real gate (open) over the test's permission answers, with the write
     * (add) scripted through $addResults.
     *
     * @return RecordAddService&MockObject
     */
    private function recordAddOver(PermissionService $permissions, ReverseTtlResolver $ttl): RecordAddService
    {
        $approval = new ChangeApprovalContext(
            $this->config,
            fn(): PermissionService => $permissions,
            fn(): ZoneRepositoryInterface => $this->createMock(ZoneRepositoryInterface::class),
            fn(): ZoneChangeRequestRepositoryInterface => $this->createMock(ZoneChangeRequestRepositoryInterface::class)
        );
        $recordAdd = $this->getMockBuilder(RecordAddService::class)
            ->setConstructorArgs([
                $this->createMock(RecordManagerService::class),
                $this->createMock(ReverseRecordCreator::class),
                $this->createMock(DomainRecordCreator::class),
                $ttl,
                $permissions,
                $this->domains,
                $approval,
            ])
            ->onlyMethods(['add'])
            ->getMock();
        $recordAdd->method('add')->willReturnCallback(function (...$args): RecordAddResult {
            $this->addCalls[] = $args;
            return array_shift($this->addResults) ?? self::added();
        });

        return $recordAdd;
    }

    private static function added(
        ?string $companion = null,
        bool $companionCreated = false,
        bool $companionWarning = false,
        ?string $companionMessage = null
    ): RecordAddResult {
        return new RecordAddResult(RecordWriteResult::ok(1), $companion, $companionCreated, $companionWarning, $companionMessage);
    }

    private static function refused(string $message, ?RecordField $field = null): RecordAddResult
    {
        return RecordAddResult::refused(RecordWriteResult::failure($message, Refusal::INVALID_INPUT, $field));
    }

    /** @param array<string, array<string, mixed>> $config */
    private function makeController(array $config = []): AddRecordController
    {
        $this->query($this->queryParams + ['id' => (string)self::ZONE_ID, 'zone_id' => (string)self::ZONE_ID]);

        return new AddRecordController($this->requestData(), true, $this->environment($this->configure($config)));
    }

    private function haltOf(AddRecordController $controller): RequestHalted
    {
        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }

    // ---------------------------------------------------------------- gates

    public function testAMissingZoneIdIsRefusedAsAnUnknownZone(): void
    {
        // The route guarantees a numeric zone_id, so the zone lookup is the only gate
        $this->query(['zone_id' => '']);
        $this->zoneName = null;
        $this->domains->expects($this->once())->method('getDomainNameById')->with(0);

        $halt = $this->haltOf(new AddRecordController([], true, $this->environment($this->configure())));

        $this->assertSame(RequestHalted::KIND_CONDITION, $halt->kind);
        $this->assertSame('There is no zone with this ID.', $halt->target);
    }

    public function testAnUnknownZoneIsRefused(): void
    {
        $this->zoneName = null;

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(RequestHalted::KIND_CONDITION, $halt->kind);
        $this->assertSame('There is no zone with this ID.', $halt->target);
    }

    public function testAZoneNeedingReviewSendsTheUserToTheZoneEditorInstead(): void
    {
        // Multi-record mode stays direct-only, so a requester is refused here
        $this->editLevel = 'none';
        $this->permissions = $this->createMock(PermissionService::class);
        $this->permissions->method('hasPermission')->willReturn(true);
        $this->permissions->method('getEditPermissionLevel')->willReturn('none');
        $this->permissions->method('getEditPermissionLevelForZone')->willReturn('none');
        $this->permissions->method('getChangeRequestPermissionLevelForZone')->willReturn('all');
        $this->permissions->method('userOwnsZone')->willReturn(true);
        $this->factory = $this->createMock(\Poweradmin\Application\Service\ControllerServiceFactory::class);
        $this->factory->method('permissionService')->willReturn($this->permissions);
        $this->factory->method('domainRepository')->willReturn($this->domains);
        $this->factory->method('recordAddService')->willReturn($this->recordAddOver($this->permissions, $this->createMock(ReverseTtlResolver::class)));

        $halt = $this->haltOf($this->makeController(['approval' => ['enabled' => true]]));

        $this->assertSame(RequestHalted::KIND_CONDITION, $halt->kind);
        $this->assertStringContainsString('approval', strtolower($halt->target));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool, 3: bool}> */
    public static function editGateProvider(): array
    {
        return [
            'read-only secondary zone' => ['SLAVE', 'all', true, false],
            'no edit permission' => ['MASTER', 'none', true, false],
            'own scope without ownership' => ['MASTER', 'own', false, false],
            'own scope with ownership' => ['MASTER', 'own', true, true],
            'unrestricted edit' => ['MASTER', 'all', false, true],
        ];
    }

    #[DataProvider('editGateProvider')]
    public function testTheZoneMustBeWritableByThisUser(string $zoneType, string $editLevel, bool $owns, bool $allowed): void
    {
        $this->zoneType = $zoneType;
        $this->editLevel = $editLevel;
        $this->ownsZone = $owns;

        $controller = $this->makeController();

        if ($allowed) {
            $controller->run();
            $this->assertSame('add_record.html', $this->output->rendered[0][0]);
            return;
        }

        $halt = $this->haltOf($controller);
        $this->assertSame(RequestHalted::KIND_CONDITION, $halt->kind);
        $this->assertSame('You do not have the permission to add a record to this zone.', $halt->target);
    }

    public function testAGetRequestOnlyShowsTheForm(): void
    {
        $this->recordAdd->expects($this->never())->method('add');

        $controller = $this->makeController();
        $controller->run();

        $params = $this->renderedParams();
        $this->assertSame(self::ZONE_ID, $params['zone_id']);
        $this->assertSame('example.com', $params['zone_name']);
        $this->assertFalse($params['is_reverse_zone']);
    }

    public function testARestrictedClientIsNotOfferedTypesItCannotSubmit(): void
    {
        $this->editLevel = 'own_as_client';

        $controller = $this->makeController();
        $controller->run();

        $this->assertNotContains('SOA', $this->renderedParams()['types']);
        $this->assertContains('A', $this->renderedParams()['types']);
    }

    public function testAReverseZoneGetsTheReverseTypeList(): void
    {
        $this->zoneName = '1.0.10.in-addr.arpa';

        $controller = $this->makeController();
        $controller->run();

        $this->assertTrue($this->renderedParams()['is_reverse_zone']);
        $this->assertContains('PTR', $this->renderedParams()['types']);
    }

    // ------------------------------------------------------ single record

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function incompleteSubmissionProvider(): array
    {
        return [
            'no content' => [['type' => 'A', 'content' => ''], 'The content field is required.'],
            'no type' => [['type' => '', 'content' => '192.0.2.1'], 'The type field is required.'],
            'content missing' => [['type' => 'A'], 'The content field is required.'],
        ];
    }

    #[DataProvider('incompleteSubmissionProvider')]
    public function testBlankContentOrTypeIsRefusedBeforeTheAddService(array $fields, string $message): void
    {
        $this->post($fields);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame($message, $halt->target);
        $this->assertCount(0, $this->addCalls);
    }

    public function testASuccessfulAddFlashesTheZoneEditorAndRedirectsThere(): void
    {
        $this->post(['type' => 'A', 'content' => '192.0.2.1', 'name' => 'www', 'ttl' => '300', 'prio' => '']);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/zones/12/edit', $halt->target);
        $this->assertSame([['success', 'The record was successfully added.']], $this->messagesFor('edit'));
    }

    public function testTheSubmittedFieldsReachTheAddServiceNormalised(): void
    {
        $this->post(['type' => 'A', 'content' => '192.0.2.1', 'name' => 'www', 'ttl' => '', 'prio' => '', 'comment' => 'hi']);

        $this->haltOf($this->makeController());

        [$zoneId, $zoneName, $name, $type, $content, $ttl, $prio, $comment, $userId, $username, $companion]
            = $this->addCalls[0];
        $this->assertSame(self::ZONE_ID, $zoneId);
        $this->assertSame('example.com', $zoneName);
        $this->assertSame('www', $name);
        $this->assertSame('A', $type);
        $this->assertSame('192.0.2.1', $content);
        $this->assertNull($ttl, 'a blank ttl is left for the service to default');
        $this->assertSame(0, $prio, 'a blank priority becomes zero');
        $this->assertSame('hi', $comment);
        $this->assertSame(self::USER_ID, $userId);
        $this->assertSame(self::USERNAME, $username);
        $this->assertSame('', $companion);
    }

    public function testTickingReverseAsksForAPtrCompanion(): void
    {
        $this->post(['type' => 'A', 'content' => '192.0.2.1', 'name' => 'www', 'reverse' => '1']);

        $this->haltOf($this->makeController());

        $this->assertSame(RecordAddResult::COMPANION_PTR, $this->addCalls[0][10]);
    }

    /** @return array<string, array{0: RecordAddResult, 1: string, 2: string}> */
    public static function companionOutcomeProvider(): array
    {
        return [
            'ptr created' => [
                new RecordAddResult(RecordWriteResult::ok(1), RecordAddResult::COMPANION_PTR, true),
                'success',
                'Record successfully added. A matching PTR record was also created.',
            ],
            'ptr created with a warning' => [
                new RecordAddResult(RecordWriteResult::ok(1), RecordAddResult::COMPANION_PTR, true, true, 'zone is odd'),
                'warning',
                'Record successfully added. zone is odd',
            ],
            'ptr refused with a reason' => [
                new RecordAddResult(RecordWriteResult::ok(1), RecordAddResult::COMPANION_PTR, false, false, 'no reverse zone'),
                'warning',
                'Record successfully added, but PTR record creation failed: no reverse zone',
            ],
            'ptr refused silently' => [
                new RecordAddResult(RecordWriteResult::ok(1), RecordAddResult::COMPANION_PTR, false),
                'success',
                'The record was successfully added, but PTR record creation failed.',
            ],
        ];
    }

    #[DataProvider('companionOutcomeProvider')]
    public function testTheCompanionOutcomeDecidesTheFlashedMessage(RecordAddResult $result, string $type, string $text): void
    {
        $this->addResults = [$result];
        $this->post(['type' => 'A', 'content' => '192.0.2.1', 'name' => 'www', 'reverse' => '1']);

        $this->haltOf($this->makeController());

        $this->assertSame([[$type, $text]], $this->messagesFor('edit'));
    }

    public function testARefusedAddKeepsTheFormValuesAndReturnsToTheAddPage(): void
    {
        $this->addResults = [self::refused('Invalid IPv4 address.', RecordField::CONTENT)];
        $this->post(['type' => 'A', 'content' => 'nope', 'name' => 'www', 'ttl' => '300', 'prio' => '5', 'comment' => 'c']);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertStringStartsWith('/zones/12/records/add?form_id=', $halt->target);
        $this->assertSame([], $this->messagesFor('edit'), 'the reason travels in the form state, not as a flash');

        $formId = substr($halt->target, strlen('/zones/12/records/add?form_id='));
        $formData = (new FormStateService())->getFormData($formId);
        $this->assertSame([
            'name' => 'www',
            'content' => 'nope',
            'type' => 'A',
            'prio' => 5,
            'ttl' => '300',
            'comment' => 'c',
            'error' => true,
            'errorMessage' => 'Invalid IPv4 address.',
            'fieldError' => 'content',
        ], $formData);
    }

    // ------------------------------------------------------ multiple records

    public function testMultiRecordModeNeedsBothTheFlagAndAnArrayOfRecords(): void
    {
        // The flag alone falls through to the single-record path
        $this->post(['multi_record_mode' => '1', 'type' => 'A', 'content' => '192.0.2.1']);

        $this->haltOf($this->makeController());

        $this->assertCount(1, $this->addCalls);
        $this->assertSame('A', $this->addCalls[0][3]);
    }

    public function testAnEmptyRecordListIsReportedThroughTheForm(): void
    {
        $this->post(['multi_record_mode' => '1', 'records' => []]);
        $this->recordAdd->expects($this->never())->method('add');

        $halt = $this->haltOf($this->makeController());

        $this->assertStringStartsWith('/zones/12/records/add?form_id=', $halt->target);
        $formId = substr($halt->target, strlen('/zones/12/records/add?form_id='));
        $this->assertSame(
            ['error' => true, 'errorMessage' => 'No records were provided.'],
            (new FormStateService())->getFormData($formId)
        );
    }

    public function testIncompleteRowsAreSkippedSilently(): void
    {
        $this->post(['multi_record_mode' => '1', 'records' => [
            ['name' => 'a', 'type' => 'A', 'content' => '192.0.2.1'],
            ['name' => 'b', 'type' => '', 'content' => '192.0.2.2'],
            ['name' => 'c', 'type' => 'A', 'content' => ''],
            'not-an-array',
        ]]);

        $halt = $this->haltOf($this->makeController());

        $this->assertCount(1, $this->addCalls);
        $this->assertSame('/zones/12/edit', $halt->target);
        $this->assertSame([['success', '1 record(s) have been added successfully.']], $this->messagesFor('edit'));
    }

    public function testCompanionCreationsAreCountedIntoTheSuccessMessage(): void
    {
        $this->addResults = [
            self::added(RecordAddResult::COMPANION_PTR, true),
            self::added(RecordAddResult::COMPANION_PTR, true),
        ];
        $this->post(['multi_record_mode' => '1', 'records' => [
            ['name' => 'a', 'type' => 'A', 'content' => '192.0.2.1', 'reverse' => '1'],
            ['name' => 'b', 'type' => 'A', 'content' => '192.0.2.2', 'reverse' => '1'],
        ]]);

        $this->haltOf($this->makeController());

        $this->assertSame(
            [['success', '2 record(s) have been added successfully. 2 matching record(s) were also created.']],
            $this->messagesFor('edit')
        );
    }

    public function testPtrWarningsAreAppendedToTheSuccessMessage(): void
    {
        $this->addResults = [self::added(RecordAddResult::COMPANION_PTR, true, true, 'reverse zone is odd')];
        $this->post(['multi_record_mode' => '1', 'records' => [
            ['name' => 'a', 'type' => 'A', 'content' => '192.0.2.1', 'reverse' => '1'],
        ]]);

        $this->haltOf($this->makeController());

        $this->assertSame(
            [['warning', '1 record(s) have been added successfully. 1 matching record(s) were also created. reverse zone is odd']],
            $this->messagesFor('edit')
        );
    }

    public function testAPartialFailureWarnsAndStillGoesToTheZoneEditor(): void
    {
        $this->addResults = [self::added(), self::refused('Invalid IPv4 address.')];
        $this->post(['multi_record_mode' => '1', 'records' => [
            ['name' => 'a', 'type' => 'A', 'content' => '192.0.2.1'],
            ['name' => 'b', 'type' => 'A', 'content' => 'nope'],
        ]]);

        $halt = $this->haltOf($this->makeController());

        $this->assertStringStartsWith('/zones/12/edit?form_id=', $halt->target);
        $this->assertSame(
            [['warning', '1 record(s) have been added successfully. 1 record(s) failed to be added. Invalid IPv4 address.']],
            $this->messagesFor('edit')
        );

        $formId = substr($halt->target, strlen('/zones/12/edit?form_id='));
        $formData = (new FormStateService())->getFormData($formId);
        $this->assertTrue($formData['multi_record_error']);
        $this->assertSame(1, $formData['failure_count']);
        $this->assertSame('Invalid IPv4 address.', $formData['errorMessage']);
    }

    public function testWhenEveryRowFailsTheWholeSubmissionIsHandedBackToTheForm(): void
    {
        $this->addResults = [self::refused('First reason.'), self::refused('Last reason.')];
        $records = [
            ['name' => 'a', 'type' => 'A', 'content' => 'nope', 'ttl' => '300', 'prio' => '0', 'comment' => 'c'],
            ['name' => 'b', 'type' => 'A', 'content' => 'also nope'],
        ];
        $this->post(['multi_record_mode' => '1', 'records' => $records]);

        $halt = $this->haltOf($this->makeController());

        $this->assertStringStartsWith('/zones/12/records/add?form_id=', $halt->target);
        $this->assertSame([], $this->messagesFor('edit'), 'a total failure is reported only through the form');

        $formId = substr($halt->target, strlen('/zones/12/records/add?form_id='));
        $formData = (new FormStateService())->getFormData($formId);
        // The last reason wins, and the first row repopulates the single-record fields
        $this->assertSame('Last reason.', $formData['errorMessage']);
        $this->assertSame(2, $formData['failure_count']);
        $this->assertSame('a', $formData['name']);
        $this->assertSame('nope', $formData['content']);
        $this->assertSame($records, $formData['saved_records']);
    }

    public function testASubmittedFormTokenClearsTheRememberedFormState(): void
    {
        $formState = new FormStateService();
        $formState->saveFormData('add_record_old', ['name' => 'stale']);
        $this->post(['type' => 'A', 'content' => '192.0.2.1', 'form_token' => 'add_record_old']);

        $this->haltOf($this->makeController());

        $this->assertNull($formState->getFormData('add_record_old'));
    }

    public function testTheFormIsRepopulatedFromASavedFormId(): void
    {
        $formState = new FormStateService();
        $formState->saveFormData('add_record_saved', [
            'name' => 'www',
            'type' => 'AAAA',
            'content' => '2001:db8::1',
            'ttl' => '300',
            'prio' => 7,
            'error' => true,
            'errorMessage' => 'Invalid address.',
            'saved_records' => [['name' => 'www']],
        ]);
        $this->query(['form_id' => 'add_record_saved']);

        $controller = $this->makeController();
        $controller->run();
        $params = $this->renderedParams();

        $this->assertSame('www', $params['name']);
        $this->assertSame('AAAA', $params['type']);
        $this->assertSame('2001:db8::1', $params['content']);
        $this->assertSame(7, $params['prio']);
        $this->assertSame([['name' => 'www']], $params['saved_records']);
        $this->assertSame('Invalid address.', $params['form_data']['errorMessage']);
    }
}
