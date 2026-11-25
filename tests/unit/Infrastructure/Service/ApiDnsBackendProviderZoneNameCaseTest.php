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
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;

/**
 * A typed zone name resolves to the local row in any case, and a lowercase row wins over a mixed-case twin.
 */
class ApiDnsBackendProviderZoneNameCaseTest extends TestCase
{
    private ApiDnsBackendProvider $provider;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_name VARCHAR(255), zone_type VARCHAR(8), zone_master VARCHAR(255))');
        $db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type) VALUES
            (1, 1, 'example.com', 'MASTER'), (3, 3, 'Mixed.Example', 'MASTER'),
            (4, 4, 'Dup.Example', 'MASTER'), (5, 5, 'dup.example', 'MASTER')");

        $this->provider = new ApiDnsBackendProvider(
            $this->createMock(PowerdnsApiClient::class),
            $db,
            $this->createMock(ConfigurationInterface::class),
            new NullLogger()
        );
    }

    public function testAZoneIsFoundByNameInAnyCaseAndALowercaseRowWins(): void
    {
        $this->assertSame(3, $this->provider->getZoneIdByName('mixed.example'));
        $this->assertSame(3, $this->provider->getZoneIdByName('MIXED.EXAMPLE'));
        $this->assertSame(1, $this->provider->getZoneIdByName('Example.COM'));
        $this->assertSame(5, $this->provider->getZoneIdByName('Dup.Example'));
        $this->assertNull($this->provider->getZoneIdByName('absent.example'));
    }
}
