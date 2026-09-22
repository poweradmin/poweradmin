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
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Psr\Log\NullLogger;
use ReflectionClass;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\StubActor;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * changeZoneType() is gated on the acting user: meta-edit-others passes
 * outright, meta-edit-own only for a zone the user owns, and a request with
 * no user at all is refused before the backend is touched.
 */
#[CoversClass(DomainManager::class)]
class DomainManagerZoneMetadataGateTest extends PermissionServiceTestCase
{
    private const CALLER_ID = 7;
    private const ZONE_ID = 5;

    /**
     * @param string[] $callerPermissions
     * @param int[] $ownedZones
     */
    private function manager(?int $actingUserId, array $callerPermissions, array $ownedZones, bool $expectBackendWrite): DomainManager
    {
        $reflection = new ReflectionClass(DomainManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();

        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('getZoneById')->willReturn(null);
        $backend->expects($expectBackendWrite ? $this->once() : $this->never())
            ->method('updateZoneType')
            ->with(self::ZONE_ID, 'NATIVE')
            ->willReturn(true);

        foreach (
            [
            'actor' => new StubActor($actingUserId),
            'backendProvider' => $backend,
            'logger' => new NullLogger(),
            'changeLogger' => $this->createMock(RecordChangeWriterInterface::class),
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
        $result = $this->manager(null, [Permission::PERM_ZONE_META_EDIT_OTHERS], [], false)->changeZoneType('NATIVE', self::ZONE_ID);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::FORBIDDEN, $result->refusal);
    }

    public function testMetaEditOthersPassesWithoutOwnership(): void
    {
        $result = $this->manager(self::CALLER_ID, [Permission::PERM_ZONE_META_EDIT_OTHERS], [], true)->changeZoneType('NATIVE', self::ZONE_ID);

        $this->assertTrue($result->success);
    }

    public function testMetaEditOwnPassesOnlyForAnOwnedZone(): void
    {
        $result = $this->manager(self::CALLER_ID, [Permission::PERM_ZONE_META_EDIT_OWN], [self::ZONE_ID], true)->changeZoneType('NATIVE', self::ZONE_ID);

        $this->assertTrue($result->success);
    }

    public function testMetaEditOwnIsRefusedForSomeoneElsesZone(): void
    {
        $result = $this->manager(self::CALLER_ID, [Permission::PERM_ZONE_META_EDIT_OWN], [99], false)->changeZoneType('NATIVE', self::ZONE_ID);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::FORBIDDEN, $result->refusal);
    }
}
