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
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use ReflectionClass;
use TestHelpers\PermissionServiceTestCase;

/**
 * Applying a template writes records straight to the backend, so it takes the
 * same content-edit standing as editing those records by hand; meta-edit alone
 * is not enough.
 */
#[CoversClass(DomainManager::class)]
class DomainManagerUpdateZoneRecordsGateTest extends PermissionServiceTestCase
{

    private const CALLER_ID = 7;
    private const ZONE_ID = 5;

    private function manager(array $callerPermissions, bool $ownsZone): DomainManager
    {
        $reflection = new ReflectionClass(DomainManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(self::CALLER_ID);

        $users = $this->createMock(DbUserRepository::class);
        $users->method('userOwnsZone')->with(self::CALLER_ID, self::ZONE_ID)->willReturn($ownsZone);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainType')->with(self::ZONE_ID)->willReturn('MASTER');

        foreach (
            [
            'userContext' => $userContext,
            'userRepository' => $users,
            'domainRepository' => $domains,
            'permissionService' => $this->buildPermissionService(permissionsByUser: [self::CALLER_ID => $callerPermissions]),
            ] as $name => $value
        ) {
            $reflection->getProperty($name)->setValue($manager, $value);
        }

        return $manager;
    }

    public function testMetaEditAndCreateGrantsAloneAreRefused(): void
    {
        $result = $this->manager([Permission::PERM_ZONE_META_EDIT_OTHERS, Permission::PERM_ZONE_MASTER_ADD], false)
            ->updateZoneRecords(86400, self::ZONE_ID, 3);

        $this->assertFalse($result->success);
        $this->assertSame(403, $result->status);
    }

    public function testUnlinkingTheTemplateWritesNoRecordsSoMetaEditIsEnough(): void
    {
        $manager = $this->manager([Permission::PERM_ZONE_META_EDIT_OTHERS], false);

        // Past the gate the call reaches the database the fixture does not have;
        // anything other than the 403 proves the gate let it through.
        try {
            $result = $manager->updateZoneRecords(86400, self::ZONE_ID, 0);
            $this->assertNotSame(403, $result->status);
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testEditOwnWithoutOwnershipIsRefused(): void
    {
        $result = $this->manager([Permission::PERM_ZONE_CONTENT_EDIT_OWN], false)
            ->updateZoneRecords(86400, self::ZONE_ID, 3);

        $this->assertSame(403, $result->status);
    }
}
