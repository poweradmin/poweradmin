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
 *
 */

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;

/**
 * The reverse-zone lookup feeds a zone id straight into the record write, so it
 * has to return the same canonical id every other zone id in this backend uses.
 *
 * Returning zones.id let the write resolve an unrelated row whose domain_id
 * happened to equal it: a matching PTR for 2.0.192.in-addr.arpa was reported as
 * created and landed inside 8.b.d.0.1.0.0.2.ip6.arpa instead.
 */
#[CoversClass(ApiDnsBackendProvider::class)]
class ApiDnsBackendProviderReverseZoneTest extends TestCase
{
    private PDO $db;
    private ApiDnsBackendProvider $provider;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT)');

        // The devcontainer shape that exposed this: the IPv4 reverse zone is
        // id 14, and a different zone carries domain_id 14.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES
            (8, 12, '168.192.in-addr.arpa'),
            (14, 13, '2.0.192.in-addr.arpa'),
            (16, 14, '8.b.d.0.1.0.0.2.ip6.arpa')");

        $this->provider = new ApiDnsBackendProvider(
            $this->createMock(PowerdnsApiClient::class),
            $this->db,
            $this->createMock(ConfigurationInterface::class),
            new NullLogger()
        );
    }

    public function testTheCanonicalIdIsReturnedRatherThanTheRowId(): void
    {
        $this->assertSame(13, $this->provider->getBestMatchingReverseZoneId('99.2.0.192.in-addr.arpa'));
    }

    public function testTheIdReturnedDoesNotResolveToAnotherZone(): void
    {
        $id = $this->provider->getBestMatchingReverseZoneId('99.2.0.192.in-addr.arpa');

        // The same preference the write path applies: a domain_id match wins
        $stmt = $this->db->prepare(
            'SELECT zone_name FROM zones WHERE (id = :id OR domain_id = :did) AND zone_name IS NOT NULL
             ORDER BY CASE WHEN id = :self_id AND domain_id = :self_did THEN 0
                           WHEN domain_id = :pref_did THEN 1 ELSE 2 END
             LIMIT 1'
        );
        foreach ([':id', ':did', ':self_id', ':self_did', ':pref_did'] as $param) {
            $stmt->bindValue($param, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $this->assertSame('2.0.192.in-addr.arpa', $stmt->fetchColumn());
    }

    public function testTheLongestMatchingReverseZoneStillWins(): void
    {
        $this->assertSame(12, $this->provider->getBestMatchingReverseZoneId('5.4.168.192.in-addr.arpa'));
    }

    public function testAnUnmatchedReverseNameIsRefused(): void
    {
        $this->assertSame(-1, $this->provider->getBestMatchingReverseZoneId('7.6.5.4.in-addr.arpa'));
    }
}
