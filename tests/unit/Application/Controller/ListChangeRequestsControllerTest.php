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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\ListChangeRequestsController;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use RuntimeException;

#[CoversClass(ListChangeRequestsController::class)]
class ListChangeRequestsControllerTest extends ChangeRequestControllerTestCase
{
    /** @var array<string, mixed>|null The filters the repository was asked for */
    private ?array $listedWith = null;

    private function makeController(bool $approvalEnabled, array $query = []): TestableListChangeRequestsController
    {
        $_GET = $query;
        $config = $this->configure($approvalEnabled);

        $pagination = $this->createMock(PaginationService::class);
        $pagination->method('getUserRowsPerPage')->willReturn(10);
        $this->factory->method('paginationService')->willReturn($pagination);

        $repository = $this->createMock(ZoneChangeRequestRepositoryInterface::class);
        $repository->method('count')->willReturn(1);
        $repository->method('list')->willReturnCallback(function (array $filters): array {
            $this->listedWith = $filters;
            return [$this->pendingRequest()];
        });
        $this->factory->method('zoneChangeRequestRepository')->willReturn($repository);

        return new TestableListChangeRequestsController([], true, $this->environment($config));
    }

    public function testFeatureOffRendersTheNotFoundPage(): void
    {
        $controller = $this->makeController(false);

        $controller->run();

        $this->assertSame('404.html', $controller->rendered[0][0]);
    }

    public function testUserWithoutAnyRoleIsRefused(): void
    {
        $this->reviewer(false);
        $this->permissions->method('getChangeRequestPermissionLevel')->willReturn('none');
        $controller = $this->makeController(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to view change requests.');
        $controller->run();
    }

    public function testRequesterOnlySeesTheirOwnPendingRequests(): void
    {
        $this->reviewer(false);
        $this->permissions->method('getChangeRequestPermissionLevel')->willReturn('own');
        $controller = $this->makeController(true);

        $controller->run();

        [$template, $params] = $controller->rendered[0];
        $this->assertSame('list_change_requests.html', $template);
        $this->assertTrue($params['own_only']);
        $this->assertSame(['status' => 'pending', 'requesterId' => self::USER_ID, 'zoneIds' => null], $this->listedWith);
        $this->assertSame(5, $params['requests'][0]['id']);
    }

    public function testOwnerScopedReviewerCannotPeekAtAnotherZone(): void
    {
        $this->permissions->method('getChangeApprovePermissionLevel')->willReturn('own');
        $this->permissions->method('getChangeRequestPermissionLevel')->willReturn('none');
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getOwnedZoneIds')->willReturn([42, 43]);
        $this->factory->method('zoneRepository')->willReturn($zones);
        $domains = $this->createMock(\Poweradmin\Domain\Repository\DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn('other.example');
        $this->factory->method('domainRepository')->willReturn($domains);
        $controller = $this->makeController(true, ['status' => 'all', 'zone_id' => '99']);

        $controller->run();

        $this->assertSame(['zoneIds' => []], $this->listedWith);
        $this->assertSame('all', $controller->rendered[0][1]['status_filter']);
    }

    public function testGlobalReviewerListsEveryZoneAndRejectsUnknownStatus(): void
    {
        $this->reviewer(true);
        $this->permissions->method('getChangeRequestPermissionLevel')->willReturn('none');
        $controller = $this->makeController(true, ['status' => 'bogus']);

        $controller->run();

        $this->assertSame(['status' => 'pending', 'zoneIds' => null], $this->listedWith);
        $this->assertFalse($controller->rendered[0][1]['own_only']);
    }
}
