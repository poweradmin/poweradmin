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
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\RepositoryFactoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use ReflectionClass;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\StubActor;

/**
 * deleteRecord() resolves the acting user's edit level and zone ownership
 * before touching the record: no user means "none", edit-own needs the zone.
 */
#[CoversClass(RecordManager::class)]
class RecordManagerDeleteGateTest extends PermissionServiceTestCase
{
    private const CALLER_ID = 7;
    private const ZONE_ID = 5;
    private const RECORD_ID = 31;

    /**
     * @param string[] $callerPermissions
     * @param int[] $ownedZones
     */
    private function manager(?int $actingUserId, array $callerPermissions, array $ownedZones): RecordManager
    {
        $reflection = new ReflectionClass(RecordManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();

        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getRecordDetailsFromRecordId')->with(self::RECORD_ID)->willReturn([
            'id' => self::RECORD_ID,
            'zid' => self::ZONE_ID,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.1',
        ]);
        $repositories = $this->createMock(RepositoryFactoryInterface::class);
        $repositories->method('createRecordRepository')->willReturn($records);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainType')->willReturn('MASTER');
        $domains->method('getDomainNameById')->willReturn('example.com');

        foreach (
            [
            'actor' => new StubActor($actingUserId),
            'repositoryFactory' => $repositories,
            'domainRepository' => $domains,
            'permissionService' => $this->buildPermissionService(
                permissionsByUser: [self::CALLER_ID => $callerPermissions],
                ownedZonesByUser: [self::CALLER_ID => $ownedZones]
            ),
            ] as $name => $value
        ) {
            $reflection->getProperty($name)->setValue($manager, $value);
        }

        return $manager;
    }

    public function testRefusesWhenNoUserIsActing(): void
    {
        $result = $this->manager(null, [Permission::PERM_ZONE_CONTENT_EDIT_OTHERS], [])->deleteRecord(self::RECORD_ID);

        $this->assertFalse($result->success);
        $this->assertSame(403, $result->status);
        $this->assertSame('You do not have the permission to delete this record.', $result->message);
    }

    public function testEditOwnIsRefusedForSomeoneElsesZone(): void
    {
        $result = $this->manager(self::CALLER_ID, [Permission::PERM_ZONE_CONTENT_EDIT_OWN], [99])->deleteRecord(self::RECORD_ID);

        $this->assertFalse($result->success);
        $this->assertSame(403, $result->status);
        $this->assertSame('You do not have the permission to delete this record.', $result->message);
    }
}
