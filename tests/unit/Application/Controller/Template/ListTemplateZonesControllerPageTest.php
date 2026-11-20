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
use Poweradmin\Application\Controller\Template\ListTemplateZonesController;
use Poweradmin\Application\Service\Web\PaginationService;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Template\ZoneTemplateAccessPolicy;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

#[CoversClass(ListTemplateZonesController::class)]
class ListTemplateZonesControllerPageTest extends SeamControllerTestCase
{
    public function testAnOverlongPageNumberRendersAnEmptyPageInsteadOfFailing(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);

        $templates = $this->createMock(ZoneTemplateService::class);
        $templates->method('getZonesUsingTemplate')->willReturn([['id' => 1, 'name' => 'a.example']]);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getFullNameById')->willReturn('Test User');

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('zoneTemplateService')->willReturn($templates);
        $policy = $this->createMock(ZoneTemplateAccessPolicy::class);
        $policy->method('canCurrentUserViewTemplate')->willReturn(true);
        $this->factory->method('zoneTemplateAccessPolicy')->willReturn($policy);
        $this->factory->method('zoneTemplateRepository')->willReturn($this->createMock(ZoneTemplateRepositoryInterface::class));
        $this->factory->method('userRepository')->willReturn($users);
        $this->factory->method('paginationService')->willReturn(new PaginationService());
        $this->query(['id' => '3', 'start' => '99999999999999999999']);

        (new ListTemplateZonesController($this->requestData(), true, $this->environment($this->configure())))->run();

        $this->assertSame('list_template_zones.html', $this->output->rendered[0][0]);
        $this->assertSame([], $this->renderedParams()['zones']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function renderedZones(string $level): array
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);
        $permissions->method('getZoneOwnershipViewPermissionLevel')->willReturn($level);
        $permissions->method('userOwnsZone')->willReturnCallback(static fn(int $userId, int $zoneId): bool => $zoneId === 1);

        $templates = $this->createMock(ZoneTemplateService::class);
        $templates->method('getZonesUsingTemplate')->willReturn([
            ['id' => 1, 'name' => 'a.example', 'owner_name' => 'alice', 'owner_fullname' => 'Alice A'],
            ['id' => 2, 'name' => 'b.example', 'owner_name' => 'bob', 'owner_fullname' => 'Bob B'],
        ]);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getFullNameById')->willReturn('Test User');

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('zoneTemplateService')->willReturn($templates);
        $policy = $this->createMock(ZoneTemplateAccessPolicy::class);
        $policy->method('canCurrentUserViewTemplate')->willReturn(true);
        $this->factory->method('zoneTemplateAccessPolicy')->willReturn($policy);
        $this->factory->method('zoneTemplateRepository')->willReturn($this->createMock(ZoneTemplateRepositoryInterface::class));
        $this->factory->method('userRepository')->willReturn($users);
        $this->factory->method('paginationService')->willReturn(new PaginationService());
        $this->query(['id' => '3']);

        (new ListTemplateZonesController($this->requestData(), true, $this->environment($this->configure())))->run();

        return $this->renderedParams()['zones'];
    }

    public function testOwnersStayVisibleAtTheAllLevel(): void
    {
        $zones = $this->renderedZones('all');

        $this->assertSame(['alice', 'bob'], array_column($zones, 'owner_name'));
    }

    public function testOwnersAreBlankedAtTheNoneLevel(): void
    {
        $zones = $this->renderedZones('none');

        $this->assertSame([null, null], array_column($zones, 'owner_name'));
        $this->assertSame([null, null], array_column($zones, 'owner_fullname'));
    }

    public function testOnlyOwnedZonesShowTheirOwnerAtTheOwnLevel(): void
    {
        $zones = $this->renderedZones('own');

        $this->assertSame(['alice', null], array_column($zones, 'owner_name'));
        $this->assertSame(['Alice A', null], array_column($zones, 'owner_fullname'));
    }
}
