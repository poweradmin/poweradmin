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
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Zone\DeleteDomainsController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Tests\Unit\Application\Controller\ControllerHalt;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The bulk delete-zones page consults the zone view gate before the bulk zone
 * lookup: without a view level the lookup is skipped, the refusal is flashed
 * and nothing is audited by name. With no names to inspect, the "all reverse"
 * default keeps the reverse list as the return page.
 */
#[CoversClass(DeleteDomainsController::class)]
class DeleteDomainsControllerViewLevelTest extends SeamControllerTestCase
{
    private string $viewLevel = 'all';

    /** @var DomainRepositoryInterface&MockObject */
    private DomainRepositoryInterface $domains;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);
        $permissions->method('getViewPermissionLevel')->willReturnCallback(fn(): string => $this->viewLevel);

        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('getZoneInfoFromIds')->willReturn([
            ['id' => 12, 'name' => '2.0.192.in-addr.arpa', 'type' => 'MASTER'],
            ['id' => 13, 'name' => '3.0.192.in-addr.arpa', 'type' => 'MASTER'],
        ]);

        $zoneManagement = $this->createMock(ZoneManagementService::class);
        $zoneManagement->method('deleteZone')->willReturn(['success' => true]);

        $this->audit = $this->createMock(AuditService::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($this->domains);
        $this->factory->method('zoneManagementService')->willReturn($zoneManagement);
        $this->factory->method('auditService')->willReturn($this->audit);
    }

    private function haltOfConfirmedPost(): ControllerHalt
    {
        $this->post(['zone_id' => ['12', '13'], 'confirm' => '1']);
        $controller = new TestableDeleteDomainsController($_POST, $this->environment($this->configure()));

        try {
            $controller->run();
        } catch (ControllerHalt $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end in a redirect.');
    }

    public function testDeletedReverseZonesAreAuditedAndReturnToTheReverseList(): void
    {
        $this->audit->expects($this->exactly(2))->method('logZoneDelete');

        $halt = $this->haltOfConfirmedPost();

        $this->assertSame('/zones/reverse', $halt->target);
        $this->assertSame([['success', 'Zones have been deleted successfully.']], $this->messagesFor('list_reverse_zones'));
        $this->assertSame([], $this->messagesFor('system'));
    }

    public function testWithoutAnyViewLevelTheZonesAreDeletedUnnamedAndTheRefusalIsFlashed(): void
    {
        $this->viewLevel = 'none';
        $this->domains->expects($this->never())->method('getZoneInfoFromIds');
        $this->audit->expects($this->never())->method('logZoneDelete');

        $halt = $this->haltOfConfirmedPost();

        $this->assertSame('/zones/reverse', $halt->target);
        $this->assertSame([['success', 'Zones have been deleted successfully.']], $this->messagesFor('list_reverse_zones'));
        $this->assertSame([['error', 'You do not have permission to view this zone.']], $this->messagesFor('system'));
    }
}
