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

namespace Poweradmin\Tests\Unit\Application\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Repository\UserRepositoryInterface;

/**
 * The caller's username for audit lines comes from the user repository, with a
 * user_id placeholder when the row is gone or its username is empty.
 */
#[CoversClass(PublicApiController::class)]
class PublicApiControllerAuthenticatedUsernameTest extends TestCase
{
    /** @param array<string, mixed>|null $row */
    private function usernameFor(int $userId, ?array $row): string
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getUserById')->with($userId)->willReturn($row);

        $services = $this->createMock(ControllerServiceFactory::class);
        $services->method('userRepository')->willReturn($users);

        $controller = new class ($services, $userId) extends PublicApiController {
            public function __construct(private readonly ControllerServiceFactory $services, int $userId)
            {
                $this->authenticatedUserId = $userId;
            }

            public function run(): void
            {
            }

            protected function services(): ControllerServiceFactory
            {
                return $this->services;
            }

            public function username(): string
            {
                return $this->getAuthenticatedUsername();
            }
        };

        return $controller->username();
    }

    public function testTheStoredUsernameIsReturned(): void
    {
        $this->assertSame('alice', $this->usernameFor(3, ['id' => 3, 'username' => 'alice']));
    }

    public function testAMissingUserFallsBackToTheIdPlaceholder(): void
    {
        $this->assertSame('user_id:3', $this->usernameFor(3, null));
    }

    public function testAnEmptyUsernameFallsBackToTheIdPlaceholder(): void
    {
        $this->assertSame('user_id:3', $this->usernameFor(3, ['id' => 3, 'username' => '']));
    }
}
