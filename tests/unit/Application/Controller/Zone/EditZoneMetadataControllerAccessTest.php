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
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\Zone\EditZoneMetadataController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The metadata editor checks the view permission before it looks the zone up,
 * so a missing id answers like a zone the user may not see.
 */
#[CoversClass(EditZoneMetadataController::class)]
class EditZoneMetadataControllerAccessTest extends SeamControllerTestCase
{
    private bool $canView = false;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canViewZoneMetadata')->willReturnCallback(fn(): bool => $this->canView);
        $permissions->method('canEditZoneMeta')->willReturn(false);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZone')->willReturn(null);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('zoneRepository')->willReturn($zones);
    }

    private function errorOf(): string
    {
        $controller = new EditZoneMetadataController(['id' => '404'], true, $this->environment($this->configure()));

        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt->target;
        }

        $this->fail('Expected the request to end early.');
    }

    public function testAMissingZoneGetsTheViewRefusalWithoutTheViewPermission(): void
    {
        $this->assertSame('You do not have the permission to view zone metadata.', $this->errorOf());
    }

    public function testAUserWhoMaySeeTheZoneStillLearnsThatItIsMissing(): void
    {
        $this->canView = true;

        $this->assertSame('Zone not found.', $this->errorOf());
    }
}
