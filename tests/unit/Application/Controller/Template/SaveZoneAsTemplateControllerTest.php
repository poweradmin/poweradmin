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
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\Template\SaveZoneAsTemplateController;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\ZoneAccessPolicy;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Saving a zone as a template reads all of its records, so it needs view access to the zone.
 */
#[CoversClass(SaveZoneAsTemplateController::class)]
class SaveZoneAsTemplateControllerTest extends SeamControllerTestCase
{
    private const ZONE = 12;

    private function open(string $viewLevel): void
    {
        // An owner whose view level is "none" still owns the zone
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canAddZoneTemplates')->willReturn(true);
        $permissions->method('isAdmin')->willReturn(false);
        $permissions->method('userOwnsZone')->willReturn(true);
        $permissions->method('getViewPermissionLevel')->willReturn($viewLevel);
        $permissions->method('canViewZone')->willReturn(ZoneAccessPolicy::levelAppliesToZone($viewLevel, true));

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn('example.com');
        $domains->method('getDomainType')->willReturn('MASTER');

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->query(['id' => (string)self::ZONE]);

        (new SaveZoneAsTemplateController($this->requestData(), true, $this->environment($this->configure())))->run();
    }

    public function testAnOwnerWithoutViewAccessIsRefused(): void
    {
        try {
            $this->open('none');
            $this->fail('Expected the request to end in an error page.');
        } catch (RequestHalted $halt) {
            $this->assertSame('You do not have permission to access this zone.', $halt->target);
        }
    }

    public function testAnOwnerWithOwnViewAccessSeesTheForm(): void
    {
        $this->open('own');

        $this->assertSame('save-zone-template.html', $this->renderedTemplate());
    }
}
