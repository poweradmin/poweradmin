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
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;

/**
 * A zone typed in any letter case resolves to its local row in API mode.
 */
class ApiDnsBackendProviderZoneLookupTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT,
            zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (1, NULL, 'Mixed.Example'), (2, NULL, 'plain.example'),
            (3, NULL, 'Dup.Example'), (4, NULL, 'dup.example')");
    }

    public function testTheProviderFindsAMixedCaseRowWhateverTheCaseTyped(): void
    {
        $provider = new ApiDnsBackendProvider(
            $this->createMock(PowerdnsApiClient::class),
            $this->db,
            $this->createMock(ConfigurationInterface::class),
            new NullLogger()
        );

        $this->assertSame(1, $provider->getZoneIdByName('mixed.EXAMPLE'));
        $this->assertSame(2, $provider->getZoneIdByName('PLAIN.example'));
        $this->assertSame(4, $provider->getZoneIdByName('Dup.Example'));
        $this->assertNull($provider->getZoneIdByName('absent.example'));
    }

    public function testTheRepositoryFindsAMixedCaseRowWhateverTheCaseTyped(): void
    {
        $repo = new ApiZoneRepository(
            $this->db,
            $this->createMock(DnsBackendProvider::class),
            'sqlite',
            $this->createMock(ConfigurationManager::class)
        );

        $this->assertSame(1, $repo->getZoneIdByName('MIXED.example'));
        $this->assertSame(4, $repo->getZoneIdByName('Dup.Example'));
        $this->assertNull($repo->getZoneIdByName('absent.example'));
    }
}
