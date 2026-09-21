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
use Poweradmin\Application\Controller\AddZoneMasterController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Domain\Service\ZoneOwnershipResolution;
use Poweradmin\Domain\Service\ZoneSigningOutcome;
use Poweradmin\Domain\Service\ZoneSigningResult;

/**
 * Characterizes the add-primary-zone form: its gates, the order of its
 * refusals, what reaches the zone service and the audit log, and where a
 * created zone sends the user.
 */
#[CoversClass(AddZoneMasterController::class)]
class AddZoneMasterControllerTest extends ZoneCreateControllerTestCase
{
    private const PAGE = 'add_zone_master';

    protected function setUp(): void
    {
        parent::setUp();
        $this->granted = [Permission::PERM_ZONE_MASTER_ADD];
    }

    /** @param array<string, array<string, mixed>> $config */
    private function makeController(array $config = []): TestableAddZoneMasterController
    {
        return new TestableAddZoneMasterController($_GET + $_POST, $this->environment($this->configure($config)));
    }

    /** @param array<string, mixed> $fields */
    private function submit(array $fields): void
    {
        $this->post($fields + ['domain' => 'example.com', 'dom_type' => 'MASTER', 'zone_template' => 'none', 'owner' => '4']);
    }

    // ---------------------------------------------------------------- gates

    public function testTheMasterAddPermissionIsRequired(): void
    {
        $this->granted = [];

        $halt = $this->haltOf(fn() => $this->makeController()->run());

        $this->assertSame(ControllerHalt::KIND_PERMISSION, $halt->kind);
        $this->assertSame('You do not have the permission to add a master zone.', $halt->target);
    }

    public function testAUserWhoCannotPickAnyOwnerIsStoppedBeforeTheForm(): void
    {
        $this->ownerBlocker = 'Zone ownership mode is groups_only but no groups exist. Create a group before adding zones.';

        $halt = $this->haltOf(fn() => $this->makeController()->run());

        $this->assertSame(ControllerHalt::KIND_ERROR, $halt->kind);
        $this->assertSame($this->ownerBlocker, $halt->target);
    }

    public function testAGetShowsTheFormWithTheConfiguredDefaults(): void
    {
        $controller = $this->makeController(['dns' => ['zone_type_default' => 'NATIVE']]);
        $controller->run();

        [$template, $params] = $controller->rendered[0];
        $this->assertSame('add_zone_master.html', $template);
        $this->assertSame('NATIVE', $params['dom_type_value']);
        $this->assertSame(['MASTER', 'NATIVE'], $params['available_zone_types'], 'an unknown server version offers no catalog kinds');
        $this->assertFalse($params['is_post']);
        $this->assertCount(0, $this->createCalls);
    }

    // ------------------------------------------------------------- refusals

    public function testABlankDomainIsLeftForTheZoneServiceToRefuse(): void
    {
        // The request validator lets a blank NotBlank field through, so the name check is the service's
        $this->createResults = [self::refusedWith(ZoneManagementService::ERR_INVALID_NAME, 'Invalid domain name')];
        $this->submit(['domain' => '']);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame('', $this->createCalls[0][0]);
        $this->assertSame([['error', 'Invalid hostname.']], $this->messagesFor(self::PAGE));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function formRefusalProvider(): array
    {
        return [
            'reverse form without a network' => [
                ['type' => 'reverse', 'domain' => 'example.com'],
                'Enter a network in CIDR notation (for example 192.168.1.0/24 or 2001:db8::/48) or a reverse zone name ending in in-addr.arpa or ip6.arpa.',
            ],
            'kind this server cannot create' => [['dom_type' => 'CONSUMER'], 'Invalid or unexpected input given.'],
            'unknown kind' => [['dom_type' => 'BOGUS'], 'Invalid or unexpected input given.'],
            'SOA-EDIT-API value not offered' => [['soa_edit_api' => 'NOT-A-CHOICE'], 'Invalid or unexpected input given.'],
        ];
    }

    #[DataProvider('formRefusalProvider')]
    public function testARefusedFormValueIsFlashedAndTheFormShownAgain(array $fields, string $message): void
    {
        $this->submit($fields);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', $message]], $this->messagesFor(self::PAGE));
        $this->assertSame('add_zone_master.html', $controller->rendered[0][0]);
        $this->assertCount(0, $this->createCalls);
    }

    public function testATemplateTheUserMayNotUseIsRefused(): void
    {
        $this->templateUsable = false;
        $this->submit(['zone_template' => '7']);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'Invalid or unexpected input given.']], $this->messagesFor(self::PAGE));
        $this->assertCount(0, $this->createCalls);
    }

    public function testAnOwnershipRefusalIsWordedForTheForm(): void
    {
        $this->ownership = ZoneOwnershipResolution::error('api wording', 400, ZoneOwnershipResolution::NO_OWNER);
        $this->submit([]);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'At least one user or group must be selected as owner.']], $this->messagesFor(self::PAGE));
        $this->assertSame('add_zone_master.html', $controller->rendered[0][0]);
        $this->assertCount(0, $this->createCalls);
    }

    public function testSigningNeedsTheDnssecPermissionForTheNewOwner(): void
    {
        $this->dnssecAllowed = false;
        $this->submit(['dnssec' => '1']);

        $controller = $this->makeController(['dnssec' => ['enabled' => true]]);
        $controller->run();

        $this->assertSame([['error', 'You do not have permission to manage DNSSEC for this zone.']], $this->messagesFor(self::PAGE));
        $this->assertCount(0, $this->createCalls);
    }

    public function testARefusedCreationIsWordedForTheForm(): void
    {
        $this->createResults = [self::refusedWith(ZoneManagementService::ERR_EXISTS, 'Domain already exists')];
        $this->submit([]);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'There is already a zone with this name.']], $this->messagesFor(self::PAGE));
        $this->assertSame('add_zone_master.html', $controller->rendered[0][0]);
        $this->assertSame([], $this->audited);
    }

    // -------------------------------------------------------------- success

    public function testACreatedForwardZoneIsAuditedFlashedAndListed(): void
    {
        $this->ownership = ZoneOwnershipResolution::success(4, [2, 3]);
        $this->submit(['domain' => ' Bücher.example ', 'zone_template' => '7', 'soa_edit_api' => 'EPOCH']);

        $halt = $this->haltOf(fn() => $this->makeController()->run());

        $this->assertSame(ControllerHalt::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/zones/forward', $halt->target);
        $this->assertSame([['success', 'Zone has been added successfully.']], $this->messagesFor('list_forward_zones'));
        $this->assertSame(
            ['xn--bcher-kva.example', 'MASTER', 4, '', '7', false, [2, 3], self::USER_ID, 'EPOCH'],
            $this->createCalls[0]
        );
        $this->assertSame([['logZoneAdd', 1, 'xn--bcher-kva.example', 'MASTER', '7', null]], $this->audited);
    }

    public function testTheReverseFormTurnsANetworkIntoTheReverseZone(): void
    {
        $this->submit(['type' => 'reverse', 'domain' => '192.168.1.0/24']);

        $halt = $this->haltOf(fn() => $this->makeController()->run());

        $this->assertSame('/zones/reverse', $halt->target);
        $this->assertSame([['success', 'Zone has been added successfully.']], $this->messagesFor('list_reverse_zones'));
        $this->assertSame('1.168.192.in-addr.arpa', $this->createCalls[0][0]);
    }

    public function testAnUnsignedPrimaryIsRectifiedWhenDnssecIsOn(): void
    {
        $this->dnssec->expects($this->once())->method('rectifyZone')->with('example.com');
        $this->submit([]);

        $halt = $this->haltOf(fn() => $this->makeController(['dnssec' => ['enabled' => true]])->run());

        $this->assertSame('/zones/forward', $halt->target);
        $this->assertFalse($this->createCalls[0][5], 'signing is only requested when the box is ticked');
    }

    /** @return array<string, array{0: ZoneSigningOutcome, 1: string, 2: string, 3: bool}> */
    public static function signingOutcomeProvider(): array
    {
        return [
            'signed' => [ZoneSigningOutcome::SIGNED, 'success', 'Zone has been created and signed with DNSSEC successfully.', false],
            'invalid zone' => [ZoneSigningOutcome::INVALID_ZONE, 'warning', "Zone was created successfully, but DNSSEC signing was skipped due to validation errors:\n\nbad SOA", true],
            'secure failed' => [ZoneSigningOutcome::SECURE_FAILED, 'warning', 'Zone was created, but securing it with DNSSEC failed. Zone validation passed, but PowerDNS API returned an error. Check PowerDNS logs for details.', true],
            'verify failed' => [ZoneSigningOutcome::VERIFY_FAILED, 'warning', 'Zone was created and signing was requested, but verification failed. Check DNSSEC keys.', true],
        ];
    }

    #[DataProvider('signingOutcomeProvider')]
    public function testTheSigningOutcomeDecidesTheFlashedMessage(ZoneSigningOutcome $outcome, string $type, string $text, bool $rectified): void
    {
        $this->createResults = [self::created(1, new ZoneSigningResult($outcome, 'bad SOA'))];
        $this->dnssec->expects($rectified ? $this->once() : $this->never())->method('rectifyZone');
        $this->submit(['dnssec' => '1']);

        $halt = $this->haltOf(fn() => $this->makeController(['dnssec' => ['enabled' => true]])->run());

        $this->assertSame('/zones/forward', $halt->target);
        $this->assertSame([[$type, $text]], $this->messagesFor('list_forward_zones'));
        $this->assertTrue($this->createCalls[0][5]);
    }
}
