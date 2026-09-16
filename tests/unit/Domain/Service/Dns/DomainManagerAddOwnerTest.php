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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use ReflectionClass;
use TestHelpers\BuildsPermissionService;

/**
 * addOwnerToZone() is the guarded write behind the change-owner page: it
 * refuses callers without meta-edit rights and users that do not exist.
 */
#[CoversClass(DomainManager::class)]
class DomainManagerAddOwnerTest extends TestCase
{
    use BuildsPermissionService;

    private const CALLER_ID = 7;

    private function manager(array $callerPermissions, array $existingUsers): DomainManager
    {
        $reflection = new ReflectionClass(DomainManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(self::CALLER_ID);

        $users = $this->createMock(DbUserRepository::class);
        $users->method('getUserById')
            ->willReturnCallback(fn(int $id): ?array => in_array($id, $existingUsers, true) ? ['id' => $id] : null);

        foreach (
            [
            'userContext' => $userContext,
            'userRepository' => $users,
            'permissionService' => $this->buildPermissionService(permissionsByUser: [self::CALLER_ID => $callerPermissions]),
            ] as $name => $value
        ) {
            $reflection->getProperty($name)->setValue($manager, $value);
        }

        return $manager;
    }

    public function testRefusesCallersWithoutMetaEditRights(): void
    {
        $result = $this->manager([], [42])->addOwnerToZone(5, 42);

        $this->assertFalse($result->success);
        $this->assertSame(403, $result->status);
    }

    public function testRefusesAnUnknownUserSoNoZoneEndsUpOrphaned(): void
    {
        $result = $this->manager([Permission::PERM_ZONE_META_EDIT_OTHERS], [])->addOwnerToZone(5, 42);

        $this->assertFalse($result->success);
        $this->assertSame(404, $result->status);
        $this->assertSame('Unknown user ID: 42', $result->message);
    }
}
