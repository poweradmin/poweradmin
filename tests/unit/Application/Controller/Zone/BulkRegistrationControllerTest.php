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

namespace Poweradmin\Tests\Unit\Application\Controller\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\Zone\BulkRegistrationController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipResolution;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Characterizes bulk registration: its gates, one ownership resolution for
 * the whole batch, and how a partly failed batch is reported.
 */
#[CoversClass(BulkRegistrationController::class)]
class BulkRegistrationControllerTest extends ZoneCreateControllerTestCase
{
    private const PAGE = 'bulk_registration';

    protected function setUp(): void
    {
        parent::setUp();
        $this->granted = [Permission::PERM_ZONE_MASTER_ADD];
    }

    private function makeController(): BulkRegistrationController
    {
        return new BulkRegistrationController($this->requestData(), true, $this->environment($this->configure()));
    }

    /** @param array<string, mixed> $fields */
    private function submit(array $fields): void
    {
        $this->post($fields + ['domains' => "one.example\ntwo.example", 'dom_type' => 'MASTER', 'zone_template' => '7', 'owner' => '4']);
    }

    public function testTheMasterAddPermissionIsRequired(): void
    {
        $this->granted = [];

        $halt = $this->haltOf(fn() => $this->makeController()->run());

        $this->assertSame(RequestHalted::KIND_PERMISSION, $halt->kind);
        $this->assertSame('You do not have the permission to add a master zone.', $halt->target);
    }

    public function testAUserWhoCannotPickAnyOwnerIsStoppedBeforeTheForm(): void
    {
        $this->ownerBlocker = 'Zone ownership mode is groups_only but no groups exist. Create a group before adding zones.';

        $halt = $this->haltOf(fn() => $this->makeController()->run());

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame($this->ownerBlocker, $halt->target);
    }

    public function testAGetShowsTheForm(): void
    {
        $controller = $this->makeController();
        $controller->run();

        [$template, $params] = $this->output->rendered[0];
        $this->assertSame('bulk_registration.html', $template);
        $this->assertSame(['MASTER', 'NATIVE'], $params['available_zone_types']);
        $this->assertSame([], $params['failed_domains']);
    }

    public function testOnlyLocallyServedKindsAreAccepted(): void
    {
        $this->submit(['dom_type' => 'SLAVE']);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'Invalid or unexpected input given.']], $this->messagesFor(self::PAGE));
        $this->assertSame('bulk_registration.html', $this->output->rendered[0][0]);
        $this->assertCount(0, $this->createCalls);
    }

    public function testATemplateTheUserMayNotUseIsRefused(): void
    {
        $this->templateUsable = false;
        $this->submit([]);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'Invalid or unexpected input given.']], $this->messagesFor(self::PAGE));
        $this->assertCount(0, $this->createCalls);
    }

    public function testAnOwnershipRefusalStopsTheWholeBatch(): void
    {
        $this->ownership = ZoneOwnershipResolution::error('api wording', Refusal::INVALID_INPUT, ZoneOwnershipResolution::UNKNOWN_GROUPS, [8]);
        $this->submit([]);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'Unknown group ID(s): 8']], $this->messagesFor(self::PAGE));
        $this->assertSame('bulk_registration.html', $this->output->rendered[0][0]);
        $this->assertCount(0, $this->createCalls);
    }

    public function testEveryDomainIsCreatedWithTheSharedOwnershipAndAudited(): void
    {
        $this->ownership = ZoneOwnershipResolution::success(4, [2]);
        $this->submit(['dom_type' => 'NATIVE']);

        $halt = $this->haltOf(fn() => $this->makeController()->run());

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/zones/forward', $halt->target);
        $this->assertSame([['success', 'Zones have been added successfully.']], $this->messagesFor('list_forward_zones'));
        $this->assertSame(
            [
                ['one.example', 'NATIVE', 4, '', '7', false, [2], self::USER_ID],
                ['two.example', 'NATIVE', 4, '', '7', false, [2], self::USER_ID],
            ],
            array_map(fn(array $call): array => array_slice($call, 0, 8), $this->createCalls)
        );
        $this->assertSame(
            [['logZoneAdd', 1, 'one.example', 'NATIVE', '7', null], ['logZoneAdd', 2, 'two.example', 'NATIVE', '7', null]],
            $this->audited
        );
    }

    public function testAPartlyFailedBatchListsTheFailuresWithTheirReasons(): void
    {
        $this->createResults = [
            self::refusedWith(ZoneManagementService::ERR_EXISTS, 'Domain already exists'),
            self::created(2),
        ];
        $this->submit([]);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['warning', 'Some zone(s) could not be added.']], $this->messagesFor(self::PAGE));
        [$template, $params] = $this->output->rendered[0];
        $this->assertSame('bulk_registration.html', $template);
        $this->assertSame([['name' => 'one.example', 'reason' => 'There is already a zone with this name.']], $params['failed_domains']);
        $this->assertSame(['two.example'], $params['added_domains']);
        $this->assertSame([['logZoneAdd', 2, 'two.example', 'MASTER', '7', null]], $this->audited);
    }
}
