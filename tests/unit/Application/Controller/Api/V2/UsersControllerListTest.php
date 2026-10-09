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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V2\UsersController;
use Poweradmin\Domain\Model\Pagination;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\User\UserManagementService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Paging of GET /api/v2/users, which follows the other v2 lists: nothing is
 * paged without per_page, and a page past the end is empty rather than a 500.
 */
class UsersControllerListTest extends V2ControllerTestCase
{
    private UserManagementService&MockObject $users;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserManagementService::class);
    }

    public function testWithoutPerPageEveryUserIsReturnedAndNoPaginationBlockIsAdded(): void
    {
        $this->users->expects($this->never())->method('countUsers');
        $this->users->expects($this->once())
            ->method('getUsersPage')
            ->with($this->callback(fn(Pagination $p): bool => $p->getOffset() === 0 && $p->getLimit() === PHP_INT_MAX))
            ->willReturn([['id' => 1], ['id' => 2]]);

        $body = $this->decode($this->listUsers());

        $this->assertCount(2, $body['data']['users']);
        $this->assertArrayHasKey('meta', $body);
        $this->assertArrayNotHasKey('pagination', $body);
    }

    public function testANegativePerPageReturnsEveryUserLikeTheOtherLists(): void
    {
        $this->users->expects($this->never())->method('countUsers');
        $this->users->method('getUsersPage')->willReturn([['id' => 1]]);

        $body = $this->decode($this->listUsers(['per_page' => -5, 'page' => 2]));

        $this->assertCount(1, $body['data']['users']);
        $this->assertArrayNotHasKey('pagination', $body);
    }

    public function testAPageIsFetchedAtItsOffsetWithPaginationMetadata(): void
    {
        $this->users->method('countUsers')->willReturn(10);
        $this->users->expects($this->once())
            ->method('getUsersPage')
            ->with($this->callback(fn(Pagination $p): bool => $p->getOffset() === 4 && $p->getLimit() === 4))
            ->willReturn([['id' => 5]]);

        $body = $this->decode($this->listUsers(['per_page' => 4, 'page' => 2]));

        $this->assertSame(['current_page' => 2, 'per_page' => 4, 'total' => 10, 'last_page' => 3], $body['pagination']);
    }

    public function testAPagePastTheEndIsAnEmptyPageEvenWhenItWouldOverflowTheOffset(): void
    {
        $this->users->method('countUsers')->willReturn(10);
        $this->users->expects($this->never())->method('getUsersPage');

        $response = $this->listUsers(['per_page' => 4, 'page' => '99999999999999999999']);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $body['data']['users']);
        $this->assertSame(['current_page' => PHP_INT_MAX, 'per_page' => 4, 'total' => 10, 'last_page' => 3], $body['pagination']);
    }

    public function testPerPageIsCappedAtTheMaximumPageSize(): void
    {
        $this->users->method('countUsers')->willReturn(1);
        $this->users->method('getUsersPage')->willReturn([['id' => 1]]);

        $body = $this->decode($this->listUsers(['per_page' => 999999]));

        $this->assertSame(10000, $body['pagination']['per_page']);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function listUsers(array $query = []): JsonResponse
    {
        $controller = $this->bareController(UsersController::class);
        $this->injectBaseCollaborators($controller, 'GET');
        $this->inject($controller, 'request', Request::create('/api/v2/users', 'GET', $query));

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canListUsers')->willReturn(true);

        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'userManagementService', $this->users);
        $this->inject($controller, 'authenticatedUserId', 1);

        return $this->callHandler($controller, 'listUsers');
    }
}
