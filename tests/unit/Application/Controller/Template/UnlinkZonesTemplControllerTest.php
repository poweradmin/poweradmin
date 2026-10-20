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
use Poweradmin\Application\Controller\Template\UnlinkZonesTemplController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The unlink confirmation page lists only the zones the unlink step would accept,
 * so posting a foreign zone id never shows that zone's name.
 */
#[CoversClass(UnlinkZonesTemplController::class)]
class UnlinkZonesTemplControllerTest extends SeamControllerTestCase
{
    private const OWN_ZONE = 12;
    private const FOREIGN_ZONE = 13;

    private bool $isUberuser = false;

    /** @var ZoneTemplateService&MockObject */
    private ZoneTemplateService $templates;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(
            fn(int $userId, string $permission): bool => $permission === Permission::PERM_USER_IS_UEBERUSER
                ? $this->isUberuser
                : $permission === Permission::PERM_ZONE_CONTENT_EDIT_OWN
        );
        $permissions->method('userOwnsZone')->willReturnCallback(static fn(int $userId, int $zoneId): bool => $zoneId === self::OWN_ZONE);

        $repository = $this->createMock(ZoneTemplateRepositoryInterface::class);
        $repository->method('getZoneTemplateDetails')->willReturn(['name' => 'basic']);

        $this->templates = $this->createMock(ZoneTemplateService::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('zoneTemplateRepository')->willReturn($repository);
        $this->factory->method('zoneTemplateService')->willReturn($this->templates);
    }

    /** @param list<string> $zoneIds */
    private function confirm(array $zoneIds): UnlinkZonesTemplController
    {
        $this->post(['zone_ids' => $zoneIds, 'template_id' => '3']);

        return new UnlinkZonesTemplController($this->requestData(), true, $this->environment($this->configure()));
    }

    public function testTheConfirmationOnlyLooksUpZonesTheUserOwns(): void
    {
        $this->templates->expects($this->once())->method('getZonesByIds')->with([self::OWN_ZONE])
            ->willReturn([['id' => self::OWN_ZONE, 'name' => 'example.com']]);

        $this->confirm([(string)self::OWN_ZONE, (string)self::FOREIGN_ZONE])->run();

        $this->assertSame([self::OWN_ZONE], $this->renderedParams()['zone_ids']);
    }

    public function testOnlyForeignZonesAreRefusedWithoutNamingThem(): void
    {
        $this->templates->expects($this->never())->method('getZonesByIds');

        try {
            $this->confirm([(string)self::FOREIGN_ZONE])->run();
            $this->fail('Expected the request to end in an error page.');
        } catch (RequestHalted $halt) {
            $this->assertSame('No valid zones selected.', $halt->target);
        }
    }

    public function testAnUberuserConfirmsAnyZone(): void
    {
        $this->isUberuser = true;
        $this->templates->expects($this->once())->method('getZonesByIds')->with([self::OWN_ZONE, self::FOREIGN_ZONE])->willReturn([]);

        $this->confirm([(string)self::OWN_ZONE, (string)self::FOREIGN_ZONE])->run();
    }
}
