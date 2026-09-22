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
use Poweradmin\Application\Controller\Zone\AddZoneSlaveController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipResolution;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Characterizes the add-secondary-zone form: its gates, what reaches the
 * zone service and the audit log, and where a created zone sends the user.
 */
#[CoversClass(AddZoneSlaveController::class)]
class AddZoneSlaveControllerTest extends ZoneCreateControllerTestCase
{
    private const PAGE = 'add_zone_slave';

    protected function setUp(): void
    {
        parent::setUp();
        $this->granted = [Permission::PERM_ZONE_SLAVE_ADD];
    }

    private function makeController(): TestableAddZoneSlaveController
    {
        return new TestableAddZoneSlaveController($_GET + $_POST, $this->environment($this->configure()));
    }

    /** @param array<string, mixed> $fields */
    private function submit(array $fields): void
    {
        $this->post($fields + ['domain' => 'example.com', 'slave_master' => '192.0.2.53', 'owner' => '4']);
    }

    public function testTheSlaveAddPermissionIsRequired(): void
    {
        $this->granted = [];

        $halt = $this->haltOf(fn() => $this->makeController()->run());

        $this->assertSame(RequestHalted::KIND_PERMISSION, $halt->kind);
        $this->assertSame('You do not have the permission to add a slave zone.', $halt->target);
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

        $this->assertSame('add_zone_slave.html', $controller->rendered[0][0]);
        $this->assertFalse($controller->rendered[0][1]['is_post']);
        $this->assertCount(0, $this->createCalls);
    }

    public function testTheReverseFormRefusesAnythingButANetworkOrReverseName(): void
    {
        $this->submit(['type' => 'reverse', 'domain' => 'example.com']);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame(
            [['error', 'Enter a network in CIDR notation (for example 192.168.1.0/24 or 2001:db8::/48) or a reverse zone name ending in in-addr.arpa or ip6.arpa.']],
            $this->messagesFor(self::PAGE)
        );
        $this->assertSame('add_zone_slave.html', $controller->rendered[0][0]);
        $this->assertCount(0, $this->createCalls);
    }

    public function testAnOwnershipRefusalIsWordedForTheForm(): void
    {
        $this->ownership = ZoneOwnershipResolution::error('api wording', Refusal::FORBIDDEN, ZoneOwnershipResolution::OTHER_OWNER_FORBIDDEN);
        $this->submit([]);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'You do not have permission to create zones for other users.']], $this->messagesFor(self::PAGE));
        $this->assertCount(0, $this->createCalls);
    }

    public function testARefusedCreationIsWordedForTheForm(): void
    {
        $this->createResults = [self::refusedWith(ZoneManagementService::ERR_INVALID_MASTER, 'Invalid master servers format: x')];
        $this->submit(['slave_master' => 'not-an-ip']);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'This is not a valid IPv4 or IPv6 address.']], $this->messagesFor(self::PAGE));
        $this->assertSame('add_zone_slave.html', $controller->rendered[0][0]);
        $this->assertSame([], $this->audited);
    }

    public function testACreatedForwardZoneIsAuditedFlashedAndListed(): void
    {
        $this->ownership = ZoneOwnershipResolution::success(null, [9]);
        $this->submit(['domain' => ' Bücher.example ']);

        $halt = $this->haltOf(fn() => $this->makeController()->run());

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/zones/forward', $halt->target);
        $this->assertSame([['success', 'Zone has been added successfully.']], $this->messagesFor('list_forward_zones'));
        $this->assertSame(
            ['xn--bcher-kva.example', 'SLAVE', null, '192.0.2.53', 'none', false, [9], self::USER_ID],
            array_slice($this->createCalls[0], 0, 8)
        );
        $this->assertSame([['logZoneAdd', 1, 'xn--bcher-kva.example', 'SLAVE', null, '192.0.2.53']], $this->audited);
    }

    public function testTheReverseFormTurnsANetworkIntoTheReverseZone(): void
    {
        $this->submit(['type' => 'reverse', 'domain' => '2001:db8::/64']);

        $halt = $this->haltOf(fn() => $this->makeController()->run());

        $this->assertSame('/zones/reverse', $halt->target);
        $this->assertSame([['success', 'Zone has been added successfully.']], $this->messagesFor('list_reverse_zones'));
        $this->assertSame('0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', $this->createCalls[0][0]);
    }
}
