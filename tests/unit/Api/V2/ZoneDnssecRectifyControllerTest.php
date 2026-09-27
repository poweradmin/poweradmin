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

namespace Poweradmin\Tests\Unit\Api\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

class ZoneDnssecRectifyControllerTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool, 2: bool, 3: int, 4: string}> */
    public static function zoneProvider(): array
    {
        return [
            'signed primary' => ['MASTER', true, false, 200, 'Zone rectified successfully'],
            'secondary' => ['SLAVE', true, false, 409, 'Secondary zones cannot be rectified'],
            'unsigned' => ['MASTER', false, false, 409, 'Zone is not DNSSEC signed'],
            'presigned' => ['MASTER', true, true, 409, 'DNSSEC for this zone is presigned and managed at the primary server'],
        ];
    }

    #[DataProvider('zoneProvider')]
    public function testOnlySignedPrimaryZonesAreRectified(string $type, bool $signed, bool $presigned, int $status, string $message): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn('example.com');
        $domains->method('getDomainType')->willReturn($type);
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canManageDnssec')->willReturn(true);
        $provider = $this->createMock(DnssecProviderInterface::class);
        $provider->method('isZoneSecured')->willReturn($signed);
        $provider->method('isZonePresigned')->willReturn($presigned);
        $provider->expects($status === 200 ? $this->once() : $this->never())->method('rectifyZone')->willReturn(true);

        $controller = new TestableZoneDnssecRectifyController($domains, $permissions, $provider, $this->createMock(PowerdnsApiClient::class));
        $response = $controller->callRectify();

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame($message, json_decode((string)$response->getContent(), true)['message']);
    }

    public function testRectifyingNeedsTheDnssecManagePermission(): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn('example.com');
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canManageDnssec')->willReturn(false);
        $provider = $this->createMock(DnssecProviderInterface::class);
        $provider->expects($this->never())->method('rectifyZone');

        $controller = new TestableZoneDnssecRectifyController($domains, $permissions, $provider, $this->createMock(PowerdnsApiClient::class));

        $this->assertSame(403, $controller->callRectify()->getStatusCode());
    }
}
