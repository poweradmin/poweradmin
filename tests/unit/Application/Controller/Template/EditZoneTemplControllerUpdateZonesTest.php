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

namespace Poweradmin\Tests\Unit\Application\Controller\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\Template\EditZoneTemplController;
use Poweradmin\Application\Service\Zone\ChangeRequestMessages;
use Poweradmin\Domain\Repository\ZoneTemplateSyncRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\ZoneWriteResult;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Applying a template to its linked zones rewrites records directly, so a zone whose
 * changes go through change requests is skipped, as in the zone editor's template change.
 */
#[CoversClass(EditZoneTemplController::class)]
class EditZoneTemplControllerUpdateZonesTest extends SeamControllerTestCase
{
    private const TEMPLATE = 3;
    private const DIRECT_DOMAIN = 21;
    private const REVIEWED_DOMAIN = 22;

    /** @var DomainManagerInterface&MockObject */
    private DomainManagerInterface $domains;

    /** @var ZoneTemplateSyncRepositoryInterface&MockObject */
    private ZoneTemplateSyncRepositoryInterface $sync;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevelForZone')
            ->willReturnCallback(static fn(int $userId, int $zoneId): string => $zoneId === self::DIRECT_DOMAIN ? 'all' : 'none');
        $permissions->method('getChangeRequestPermissionLevelForZone')->willReturn('all');
        $permissions->method('userOwnsZone')->willReturn(true);

        $templates = $this->createMock(ZoneTemplateService::class);
        $templates->method('getZoneAndDomainIdsByTemplate')->willReturn([
            ['zone_id' => 1, 'domain_id' => self::DIRECT_DOMAIN],
            ['zone_id' => 2, 'domain_id' => self::REVIEWED_DOMAIN],
        ]);

        $this->domains = $this->createMock(DomainManagerInterface::class);
        $this->sync = $this->createMock(ZoneTemplateSyncRepositoryInterface::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('zoneTemplateService')->willReturn($templates);
        $this->factory->method('domainManager')->willReturn($this->domains);
        $this->factory->method('zoneTemplateSync')->willReturn($this->sync);
    }

    private function applyToLinkedZones(): void
    {
        $controller = new EditZoneTemplController($this->requestData(), true, $this->environment($this->config));
        try {
            $controller->updateZoneRecords(self::TEMPLATE);
            $this->fail('Expected a redirect.');
        } catch (RequestHalted $halt) {
            $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        }
    }

    public function testAZoneRequiringApprovalIsSkippedAndReported(): void
    {
        $this->configure(['approval' => ['enabled' => true]]);
        $this->post(['update_zones' => '1']);

        $this->domains->expects($this->once())->method('updateZoneRecords')
            ->with($this->anything(), self::DIRECT_DOMAIN, self::TEMPLATE)
            ->willReturn(ZoneWriteResult::ok(self::DIRECT_DOMAIN));
        $this->sync->expects($this->once())->method('markZonesAsSynced')->with([1], self::TEMPLATE);

        $this->applyToLinkedZones();

        $messages = $this->messagesFor('edit_zone_templ');
        $this->assertContains(['warning', '1 zone could not be updated.'], $messages);
        $this->assertContains(['error', ChangeRequestMessages::requiresApproval()], $this->messagesFor('system'));
    }

    public function testEveryZoneIsUpdatedWhileApprovalIsOff(): void
    {
        $this->post(['update_zones' => '1']);

        $this->domains->expects($this->exactly(2))->method('updateZoneRecords')
            ->willReturn(ZoneWriteResult::ok(1));
        $this->sync->expects($this->once())->method('markZonesAsSynced')->with([1, 2], self::TEMPLATE);

        $this->applyToLinkedZones();
    }
}
