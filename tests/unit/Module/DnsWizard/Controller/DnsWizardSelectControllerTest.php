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
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Module\DnsWizard\Controller\DnsWizardSelectController;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The wizard picker checks edit access before it looks the zone up, so a missing
 * id answers like a zone the user cannot edit.
 */
#[CoversClass(DnsWizardSelectController::class)]
class DnsWizardSelectControllerTest extends SeamControllerTestCase
{
    private string $editLevel = 'own';
    private ?string $zoneName = null;
    private string $zoneType = 'MASTER';

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevel')->willReturnCallback(fn(): string => $this->editLevel);
        $permissions->method('userOwnsZone')->willReturn(false);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturnCallback(fn(): ?string => $this->zoneName);
        $domains->method('getDomainType')->willReturnCallback(fn(): string => $this->zoneType);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
    }

    private function errorOf(): string
    {
        $controller = new DnsWizardSelectController(['id' => '12'], true, $this->environment($this->configure(['dns_wizards' => ['enabled' => true]])));

        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);

            return $halt->target;
        }

        $this->fail('Expected the request to end in an error page.');
    }

    public function testAMissingZoneAnswersAnOwnLevelUserLikeAForeignZone(): void
    {
        $this->assertSame('You do not have permission to add records to this zone.', $this->errorOf());

        $this->zoneName = 'example.com';
        $this->assertSame('You do not have permission to add records to this zone.', $this->errorOf());
    }

    public function testAUserWhoMayEditEveryZoneStillLearnsThatAnIdIsMissing(): void
    {
        $this->editLevel = 'all';

        $this->assertSame('Zone not found.', $this->errorOf());
    }

    public function testAReadOnlyZoneIsRefusedEvenToAUserWhoMayEditEveryZone(): void
    {
        $this->editLevel = 'all';
        $this->zoneName = 'example.com';
        $this->zoneType = 'SLAVE';

        $this->assertSame('You do not have permission to add records to this zone.', $this->errorOf());
    }
}
