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
use Poweradmin\Application\Controller\Zone\DeleteDomainController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestService;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The delete-zone page consults the zone view gate before it looks the zone up
 * for the redirect target: without a view level the lookup is skipped, the
 * refusal is flashed and the forward list is the fallback.
 */
#[CoversClass(DeleteDomainController::class)]
class DeleteDomainControllerViewLevelTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;

    private string $viewLevel = 'all';
    private bool $canDeleteOthers = true;
    private string $changeRequestLevel = 'none';

    /** @var DomainRepositoryInterface&MockObject */
    private DomainRepositoryInterface $domains;

    /** @var ZoneManagementService&MockObject */
    private ZoneManagementService $zoneManagement;

    /** @var ZoneChangeRequestService&MockObject */
    private ZoneChangeRequestService $changeRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('userOwnsZone')->willReturn(false);
        $permissions->method('canPerformZoneAction')->willReturn(false);
        $permissions->method('hasPermission')->willReturnCallback(fn(): bool => $this->canDeleteOthers);
        $permissions->method('getViewPermissionLevel')->willReturnCallback(fn(): string => $this->viewLevel);
        $permissions->method('getChangeRequestPermissionLevelForZone')->willReturnCallback(fn(): string => $this->changeRequestLevel);

        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('getZoneInfoFromId')->willReturn(['id' => self::ZONE_ID, 'name' => '2.0.192.in-addr.arpa', 'type' => 'MASTER']);

        $this->zoneManagement = $this->createMock(ZoneManagementService::class);
        $this->changeRequests = $this->createMock(ZoneChangeRequestService::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($this->domains);
        $this->factory->method('zoneManagementService')->willReturn($this->zoneManagement);
        $this->factory->method('zoneChangeRequestService')->willReturn($this->changeRequests);
        $this->factory->method('auditService')->willReturn($this->createMock(AuditService::class));
    }

    /** @param array<string, array<string, mixed>> $config */
    private function haltOfConfirmedPost(array $config = []): RequestHalted
    {
        $this->post(['id' => (string)self::ZONE_ID]);
        $controller = new TestableDeleteDomainController(['id' => (string)self::ZONE_ID], $this->environment($this->configure($config)));

        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end in a redirect.');
    }

    public function testAFailedDeleteReturnsToTheListTheZoneBelongsTo(): void
    {
        $this->zoneManagement->method('deleteZone')->willReturn(['success' => false]);

        $halt = $this->haltOfConfirmedPost();

        $this->assertSame('/zones/reverse', $halt->target);
        $this->assertSame([['error', 'The zone could not be deleted.']], $this->messagesFor('system'));
    }

    public function testWithoutAnyViewLevelAFailedDeleteFlashesTheRefusalAndFallsBackToTheForwardList(): void
    {
        $this->viewLevel = 'none';
        $this->domains->expects($this->never())->method('getZoneInfoFromId');
        $this->zoneManagement->method('deleteZone')->willReturn(['success' => false]);

        $halt = $this->haltOfConfirmedPost();

        $this->assertSame('/zones/forward', $halt->target);
        $this->assertSame([
            ['error', 'You do not have permission to view this zone.'],
            ['error', 'The zone could not be deleted.'],
        ], $this->messagesFor('system'));
    }

    public function testWithoutAnyViewLevelADeletionRequestIsStillFiledAndTheRefusalFlashed(): void
    {
        $this->viewLevel = 'none';
        $this->canDeleteOthers = false;
        $this->changeRequestLevel = 'all';
        $this->domains->expects($this->never())->method('getZoneInfoFromId');
        $this->changeRequests->expects($this->once())->method('fileZoneDelete')
            ->with(self::ZONE_ID, self::USER_ID, self::USERNAME, null)
            ->willReturn(ZoneChangeRequestResult::ok(9, 'filed'));

        $halt = $this->haltOfConfirmedPost(['approval' => ['enabled' => true]]);

        $this->assertSame('/zones/forward', $halt->target);
        $this->assertSame([['error', 'You do not have permission to view this zone.']], $this->messagesFor('system'));
        $this->assertSame([['success', 'The zone deletion was submitted for approval.']], $this->messagesFor('list_forward_zones'));
    }
}
