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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;

/**
 * The kind and master lookups serve the cached zones row for any zone kind; PowerDNS
 * is asked once, and only when the local row has no kind cached yet.
 */
#[CoversClass(ApiDnsBackendProvider::class)]
class ApiDnsBackendProviderZoneMasterTest extends TestCase
{
    private PowerdnsApiClient&MockObject $client;
    private ApiDnsBackendProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE zones (
            id INTEGER PRIMARY KEY,
            domain_id INTEGER,
            zone_name TEXT,
            zone_type TEXT,
            zone_master TEXT
        )");
        $db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master) VALUES
            (1, 1, 'native.example.com', 'NATIVE', NULL),
            (2, 2, 'slave.example.com', 'SLAVE', '192.0.2.1'),
            (3, 3, 'consumer.example.com', 'CONSUMER', '192.0.2.2'),
            (4, 4, 'untyped.example.com', NULL, NULL)");

        $this->client = $this->createMock(PowerdnsApiClient::class);
        $this->provider = new ApiDnsBackendProvider(
            $this->client,
            $db,
            $this->createMock(ConfigurationInterface::class),
            new NullLogger()
        );
    }

    #[Test]
    public function answersFromTheCachedRowWithoutAskingPowerdns(): void
    {
        $this->client->expects($this->never())->method('getZone');

        $this->assertSame('192.0.2.1', $this->provider->getZoneMasterById(2));
        $this->assertSame('192.0.2.2', $this->provider->getZoneMasterById(3));
        $this->assertNull($this->provider->getZoneMasterById(1));
        $this->assertNull($this->provider->getZoneMasterById(99));
        $this->assertSame('CONSUMER', $this->provider->getZoneTypeById(3));
        $this->assertSame('NATIVE', $this->provider->getZoneTypeById(99));
    }

    #[Test]
    public function asksPowerdnsOnceWhenTheLocalRowHasNoKind(): void
    {
        $this->client->expects($this->once())
            ->method('getZone')
            ->with('untyped.example.com.', false)
            ->willReturn(['name' => 'untyped.example.com.', 'kind' => 'Consumer', 'masters' => ['192.0.2.3']]);

        $this->assertSame('192.0.2.3', $this->provider->getZoneMasterById(4));
    }

    #[Test]
    public function asksPowerdnsForTheKindWhenTheLocalRowHasNone(): void
    {
        $this->client->expects($this->once())
            ->method('getZone')
            ->with('untyped.example.com.', false)
            ->willReturn(['name' => 'untyped.example.com.', 'kind' => 'Slave', 'masters' => ['192.0.2.3']]);

        $this->assertSame('SLAVE', $this->provider->getZoneTypeById(4));
    }
}
