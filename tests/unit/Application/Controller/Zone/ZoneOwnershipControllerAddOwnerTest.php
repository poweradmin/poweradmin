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

use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Zone\ZoneOwnershipController;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\ZoneWriteResult;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;

/**
 * Adding an owner the zone already has succeeds but is not a new grant, so it
 * writes no audit line and sends no zone-access mail.
 */
class ZoneOwnershipControllerAddOwnerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 7;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit = $this->createMock(AuditService::class);
        $this->factory->method('auditService')->willReturn($this->audit);
        $this->factory->method('permissionService')->willReturn($this->createMock(PermissionService::class));
    }

    public function testReAddingAnExistingOwnerIsNotAudited(): void
    {
        $this->audit->expects($this->never())->method('logZoneOwnerAdd');

        $this->submit(ZoneWriteResult::unchanged(self::ZONE_ID));

        $this->assertSame([['success', 'Owner has been added successfully.']], $this->messagesFor('zone-ownership'));
    }

    public function testAddingANewOwnerIsAudited(): void
    {
        $this->audit->expects($this->once())->method('logZoneOwnerAdd')->with(self::ZONE_ID, 'example.com', 5);

        $this->submit(ZoneWriteResult::ok(self::ZONE_ID));
    }

    private function submit(ZoneWriteResult $result): void
    {
        $config = $this->configure(['dns' => ['zone_ownership_mode' => 'both']]);
        $manager = $this->createMock(DomainManagerInterface::class);
        $manager->method('addOwnerToZone')->with(self::ZONE_ID, 5)->willReturn($result);
        $this->factory->method('domainManager')->willReturn($manager);
        $this->factory->method('zoneOwnershipModeService')->willReturn(new ZoneOwnershipModeService($config));
        $this->post(['newowner' => '5']);

        $controller = new ZoneOwnershipController([], true, $this->environment($config));

        $handler = new ReflectionMethod($controller, 'handleFormSubmission');
        $handler->invoke($controller, self::ZONE_ID, 'example.com', self::USER_ID, true);
    }
}
