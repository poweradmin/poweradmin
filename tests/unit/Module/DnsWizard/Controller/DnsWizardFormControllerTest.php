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

namespace Poweradmin\Tests\Unit\Module\DnsWizard\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Service\ChangeApprovalContext;
use Poweradmin\Application\Service\RecordAddService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\DomainRecordCreator;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Session\FormStateService;
use Poweradmin\Module\DnsWizard\Controller\DnsWizardFormController;
use Poweradmin\Tests\Unit\Application\Controller\ControllerHalt;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Characterizes the wizard form page: the gates in front of it, what a
 * submission writes, and where each refusal sends the user.
 */
#[CoversClass(DnsWizardFormController::class)]
class DnsWizardFormControllerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;

    private string $editLevel = 'all';
    private bool $ownsZone = true;
    private string $zoneType = 'MASTER';
    private ?string $zoneName = 'example.com';
    private string $requestLevel = 'none';

    /** @var list<RecordWriteResult> */
    private array $writeResults = [];

    /** @var list<array<int, mixed>> */
    private array $writes = [];

    /** @var RecordManagerService&MockObject */
    private RecordManagerService $records;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);
        $permissions->method('getEditPermissionLevel')->willReturnCallback(fn(): string => $this->editLevel);
        $permissions->method('getEditPermissionLevelForZone')->willReturnCallback(fn(): string => $this->editLevel);
        $permissions->method('getChangeRequestPermissionLevelForZone')->willReturnCallback(fn(): string => $this->requestLevel);
        $permissions->method('userOwnsZone')->willReturnCallback(fn(): bool => $this->ownsZone);
        $permissions->method('canEditZoneContent')->willReturnCallback(
            fn(): bool => $this->zoneType === 'MASTER' && ($this->editLevel === 'all' || ($this->editLevel !== 'none' && $this->ownsZone))
        );
        $permissions->method('canEditZoneRecord')->willReturn(true);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('zoneIdExists')->willReturnCallback(fn(): bool => $this->zoneName !== null);
        $domains->method('getDomainType')->willReturnCallback(fn(): string => $this->zoneType);
        $domains->method('getDomainNameById')->willReturnCallback(fn(): ?string => $this->zoneName);

        $this->records = $this->createMock(RecordManagerService::class);
        $this->records->method('createRecord')->willReturnCallback(function (...$args): RecordWriteResult {
            $this->writes[] = $args;
            return array_shift($this->writeResults) ?? RecordWriteResult::ok(1);
        });

        $ttl = $this->createMock(ReverseTtlResolver::class);
        $ttl->method('resolveTtlForType')->willReturn(3600);
        $ttl->method('resolvePtrTtl')->willReturnArgument(0);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('recordManagerService')->willReturn($this->records);
        $this->factory->method('reverseTtlResolver')->willReturn($ttl);

        // The real add flow over the mocked collaborators, so the gates and the
        // write are exercised as production wires them
        $this->factory->method('recordAddService')->willReturn(new RecordAddService(
            $this->records,
            $this->createMock(ReverseRecordCreator::class),
            $this->createMock(DomainRecordCreator::class),
            $ttl,
            $permissions,
            $domains,
            new ChangeApprovalContext(
                ConfigurationManager::getInstance(),
                fn(): PermissionService => $permissions,
                fn(): ZoneRepositoryInterface => $this->createMock(ZoneRepositoryInterface::class),
                fn(): ZoneChangeRequestRepositoryInterface => $this->createMock(ZoneChangeRequestRepositoryInterface::class)
            )
        ));
    }

    /** @param array<string, array<string, mixed>> $config */
    private function makeController(array $config = [], string $type = 'spf', string $id = '12'): TestableDnsWizardFormController
    {
        $config += ['dns_wizards' => ['enabled' => true, 'available_types' => ['SPF']]];
        $request = ['id' => $id, 'type' => $type] + $_GET + $_POST;

        return new TestableDnsWizardFormController($request, $this->environment($this->configure($config)));
    }

    private function haltOf(TestableDnsWizardFormController $controller): ControllerHalt
    {
        try {
            $controller->run();
        } catch (ControllerHalt $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }

    /** @param array<string, string> $fields */
    private function submit(array $fields): void
    {
        $this->post($fields + ['submit_wizard' => '1']);
    }

    // ---------------------------------------------------------------- gates

    public function testDisabledWizardsAreRefused(): void
    {
        $halt = $this->haltOf($this->makeController(['dns_wizards' => ['enabled' => false]]));

        $this->assertSame(ControllerHalt::KIND_ERROR, $halt->kind);
        $this->assertSame('DNS wizards are not enabled.', $halt->target);
    }

    public function testANonNumericZoneIdIsRefused(): void
    {
        $halt = $this->haltOf($this->makeController(id: 'abc'));

        $this->assertSame(ControllerHalt::KIND_ERROR, $halt->kind);
        $this->assertSame('Invalid zone ID.', $halt->target);
    }

    public function testAnUnknownZoneIsRefused(): void
    {
        $this->zoneName = null;

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(ControllerHalt::KIND_ERROR, $halt->kind);
        $this->assertSame('Zone not found.', $halt->target);
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
            $this->assertSame('dns_wizard_form.html', $controller->rendered[0][0]);
            return;
        }

        $halt = $this->haltOf($controller);
        $this->assertSame(ControllerHalt::KIND_ERROR, $halt->kind);
        $this->assertSame('You do not have permission to add records to this zone.', $halt->target);
    }

    public function testAZoneNeedingReviewSendsTheUserToTheZoneEditorInstead(): void
    {
        $this->requestLevel = 'all';

        $halt = $this->haltOf($this->makeController(['approval' => ['enabled' => true, 'require_review_for_all' => true]]));

        $this->assertSame(ControllerHalt::KIND_ERROR, $halt->kind);
        $this->assertSame('This zone requires approval for changes; use the zone editor to submit a change request.', $halt->target);
    }

    public function testAnUnavailableWizardTypeIsRefused(): void
    {
        $halt = $this->haltOf($this->makeController(type: 'dmarc'));

        $this->assertSame(ControllerHalt::KIND_ERROR, $halt->kind);
        $this->assertSame('Invalid wizard type.', $halt->target);
    }

    // ----------------------------------------------------------------- form

    public function testAGetRequestRendersTheWizardForm(): void
    {
        $this->records->expects($this->never())->method('createRecord');

        $controller = $this->makeController();
        $controller->run();

        [$template, $params] = $controller->rendered[0];
        $this->assertSame('dns_wizard_form.html', $template);
        $this->assertSame(self::ZONE_ID, $params['zone_id']);
        $this->assertSame('example.com', $params['zone_name']);
        $this->assertSame('SPF', $params['wizard']['type']);
        $this->assertSame('TXT', $params['wizard']['recordType']);
        $this->assertTrue($params['formData']['use_mx'], 'schema defaults seed the form');
        $this->assertFalse($params['showWarnings']);
    }

    public function testSavedFormDataAndWarningsAreRestoredOnce(): void
    {
        $formState = new FormStateService();
        $formState->saveFormData('dns_wizard_form_x', ['ip4' => '192.0.2.0/24', '_warnings' => ['careful']]);
        $this->query(['form_id' => 'dns_wizard_form_x', 'show_warnings' => '1']);

        $controller = $this->makeController();
        $controller->run();

        $params = $controller->rendered[0][1];
        $this->assertSame('192.0.2.0/24', $params['formData']['ip4']);
        $this->assertArrayNotHasKey('_warnings', $params['formData']);
        $this->assertSame(['careful'], $params['warnings']);
        $this->assertTrue($params['showWarnings']);
        $this->assertNull($formState->getFormData('dns_wizard_form_x'));
    }

    // ----------------------------------------------------------- submission

    public function testAValidSubmissionWritesTheGeneratedRecordAndReturnsToTheZoneEditor(): void
    {
        $this->submit(['use_mx' => '1', 'use_a' => '1', 'all' => 'softfail']);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(ControllerHalt::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/zones/12/edit', $halt->target);
        $this->assertSame([['success', 'The record was successfully added.']], $this->messagesFor('edit'));
        $this->assertSame(
            [self::ZONE_ID, 'example.com', 'TXT', '"v=spf1 mx a ~all"', 3600, 0, '', self::USERNAME],
            array_slice($this->writes[0], 0, 8)
        );
    }

    public function testAnInvalidSubmissionIsSentBackToTheFormWithItsValues(): void
    {
        $this->submit(['use_mx' => '1', 'ip4' => 'not-an-ip']);
        $this->records->expects($this->never())->method('createRecord');

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(ControllerHalt::KIND_REDIRECT, $halt->kind);
        $this->assertStringStartsWith('/zones/12/wizard/spf?form_id=', $halt->target);
        $this->assertSame(
            [['error', 'Validation failed: Invalid IPv4 address or network: not-an-ip']],
            $this->messagesFor('dns_wizard_form')
        );

        $formId = substr($halt->target, strlen('/zones/12/wizard/spf?form_id='));
        $this->assertSame(['use_mx' => '1', 'ip4' => 'not-an-ip'], (new FormStateService())->getFormData($formId));
    }

    public function testWarningsAreShownBeforeWritingUnlessAcknowledged(): void
    {
        $includes = implode("\n", array_map(fn(int $i): string => "spf$i.example.net", range(1, 8)));
        $this->submit(['use_mx' => '1', 'includes' => $includes]);
        $this->records->expects($this->never())->method('createRecord');

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(ControllerHalt::KIND_REDIRECT, $halt->kind);
        $this->assertStringContainsString('/zones/12/wizard/spf?form_id=', $halt->target);
        $this->assertStringEndsWith('&show_warnings=1', $halt->target);
        $this->assertSame([], $this->messagesFor('dns_wizard_form'));
    }

    public function testAcknowledgedWarningsLetTheWriteThrough(): void
    {
        $includes = implode("\n", array_map(fn(int $i): string => "spf$i.example.net", range(1, 8)));
        $this->submit(['use_mx' => '1', 'includes' => $includes, 'warnings_acknowledged' => '1']);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame('/zones/12/edit', $halt->target);
        $this->assertCount(1, $this->writes);
    }

    public function testARefusedWriteReportsTheReasonOnTheForm(): void
    {
        $this->writeResults = [RecordWriteResult::failure('There is already a record with this name and content.')];
        $this->submit(['use_mx' => '1']);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(ControllerHalt::KIND_REDIRECT, $halt->kind);
        $this->assertStringStartsWith('/zones/12/wizard/spf?form_id=', $halt->target);
        $this->assertSame(
            [['error', 'There is already a record with this name and content.']],
            $this->messagesFor('dns_wizard_form')
        );
        $this->assertSame([], $this->messagesFor('edit'));
    }
}
