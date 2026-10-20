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

namespace Poweradmin\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

/**
 * An RFC 2317 zone, whose name holds a slash, goes through its whole life by the
 * PowerDNS zone id (0=2F26...) on both devcontainer PowerDNS versions (#1685).
 * Each case is skipped when its PowerDNS API is unreachable.
 */
class ClasslessReverseZoneApiIntegrationTest extends TestCase
{
    private const PDNS_API_KEY = 'fxiBmBFx7MITw5ECRMOr10ghlxGMvWZA';
    private const ZONE = '0/26.2.0.192.in-addr.arpa.';
    private const PTR = '1.0/26.2.0.192.in-addr.arpa.';

    private ?PowerdnsApiClient $client = null;

    public static function powerdnsApis(): array
    {
        return [
            'PowerDNS 4.9' => ['http://127.0.0.1:8181'],
            'PowerDNS 5.1' => ['http://127.0.0.1:8186'],
        ];
    }

    protected function tearDown(): void
    {
        $this->client?->deleteZone(new Zone(self::ZONE));
        $this->client = null;
    }

    #[DataProvider('powerdnsApis')]
    public function testClasslessReverseZoneLifecycle(string $apiUrl): void
    {
        $this->requireApi($apiUrl);
        $this->client = $this->newClient($apiUrl);
        $zone = new Zone(self::ZONE);
        // A run that died halfway leaves the zone behind
        $this->client->deleteZone($zone);

        $created = $this->client->createZoneWithData([
            'name' => self::ZONE,
            'kind' => 'Native',
            'nameservers' => ['ns1.example.com.'],
        ]);
        $this->assertNotNull($created, 'zone creation failed');
        $this->assertSame('0=2F26.2.0.192.in-addr.arpa.', $created['id'] ?? null);

        $this->assertTrue($this->client->patchZoneRRsets(self::ZONE, [$this->ptrRrset('host1.example.com.')]), 'adding the PTR failed');
        $this->assertTrue($this->client->patchZoneRRsets(self::ZONE, [$this->ptrRrset('host2.example.com.')]), 'editing the PTR failed');

        $read = $this->newClient($apiUrl)->getZone(self::ZONE);
        $this->assertNotNull($read, 'reading the zone back failed');
        $this->assertSame(self::ZONE, $read['name']);
        $this->assertSame(['host2.example.com.'], $this->ptrContents($read));

        $this->assertTrue($this->client->rectifyZone($zone), 'rectify failed');
        $this->assertIsArray($this->client->getZoneMetadata($zone));

        $this->assertTrue($this->client->deleteZone($zone), 'zone deletion failed');
        $this->assertNull($this->newClient($apiUrl)->getZone(self::ZONE), 'zone still present after deletion');
        $this->client = null;
    }

    private function ptrRrset(string $target): array
    {
        return [
            'name' => self::PTR,
            'type' => 'PTR',
            'ttl' => 3600,
            'changetype' => 'REPLACE',
            'records' => [['content' => $target, 'disabled' => false]],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function ptrContents(array $zoneData): array
    {
        foreach ($zoneData['rrsets'] ?? [] as $rrset) {
            if (($rrset['name'] ?? '') === self::PTR && ($rrset['type'] ?? '') === 'PTR') {
                return array_column($rrset['records'], 'content');
            }
        }
        return [];
    }

    private function newClient(string $apiUrl): PowerdnsApiClient
    {
        return new PowerdnsApiClient(new HttpClient($apiUrl, self::PDNS_API_KEY), 'localhost');
    }

    private function requireApi(string $apiUrl): void
    {
        $ch = @curl_init($apiUrl . '/api/v1/servers/localhost');
        if ($ch === false) {
            $this->markTestSkipped('curl not available');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . self::PDNS_API_KEY],
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($result === false || $httpCode !== 200) {
            $this->markTestSkipped('PowerDNS API not available at ' . $apiUrl);
        }
    }
}
