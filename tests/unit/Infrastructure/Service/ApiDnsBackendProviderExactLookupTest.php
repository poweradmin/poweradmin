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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Session\ApiStatusService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Poweradmin\Infrastructure\Session\ArraySession;
use Psr\Log\NullLogger;

/**
 * The API backend's cross-zone lookups (#1813): a name is read from the most specific zone
 * that holds it rather than through the search endpoint, whose substring matches and cap
 * could push the record out; content uses exact searches and reports when one fills the limit.
 */
class ApiDnsBackendProviderExactLookupTest extends TestCase
{
    private PowerdnsApiClient&MockObject $client;
    private ApiDnsBackendProvider $provider;
    private PDO $db;

    protected function setUp(): void
    {
        $db = $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_name VARCHAR(255), zone_type VARCHAR(8), zone_master VARCHAR(255))');
        $db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type) VALUES (1, 1, 'example.com', 'MASTER'), (2, 2, 'sub.example.com', 'MASTER')");

        $this->client = $this->createMock(PowerdnsApiClient::class);
        $this->provider = new ApiDnsBackendProvider(
            $this->client,
            $db,
            $this->createMock(ConfigurationInterface::class),
            new ApiStatusService(new ArraySession()),
            new NullLogger()
        );
    }

    private static function rrset(string $name, string $type, string $content): array
    {
        return ['name' => $name, 'type' => $type, 'ttl' => 3600, 'records' => [['content' => $content, 'disabled' => false]], 'comments' => []];
    }

    public function testANameIsReadFromTheMostSpecificZoneThatHoldsIt(): void
    {
        $this->client->expects($this->never())->method('searchData');
        $this->client->expects($this->once())->method('getZoneRrset')
            ->with('sub.example.com.', 'www.sub.example.com.', null)
            ->willReturn(['name' => 'sub.example.com.', 'rrsets' => [
                self::rrset('www.sub.example.com.', 'A', '192.0.2.1'),
                self::rrset('www.sub.example.com.', 'TXT', '"hello"'),
            ]]);

        $records = $this->provider->findRecordsByName('WWW.sub.example.com.');

        $this->assertSame(['A', 'TXT'], array_column($records, 'type'));
        $this->assertSame([2, 2], array_column($records, 'domain_id'));
    }

    public function testATypedNameLookupAsksTheZoneForThatType(): void
    {
        $this->client->expects($this->once())->method('getZoneRrset')
            ->with('example.com.', 'www.example.com.', 'CNAME')
            ->willReturn(['name' => 'example.com.', 'rrsets' => [self::rrset('www.example.com.', 'CNAME', 'other.org.')]]);

        $records = $this->provider->findRecordsByName('www.example.com', 'CNAME');

        $this->assertCount(1, $records);
        $this->assertSame('other.org', $records[0]['content']);
    }

    public function testANameNoLocalZoneHoldsFallsBackToAnExactSearch(): void
    {
        $this->client->expects($this->never())->method('getZoneRrset');
        $this->client->expects($this->once())->method('searchData')
            ->with('www.unsynced.org', 'record', 10000)
            ->willReturn([
                ['object_type' => 'record', 'name' => 'www.unsynced.org.', 'type' => 'A', 'content' => '192.0.2.7', 'ttl' => 60, 'zone' => 'unsynced.org.'],
                ['object_type' => 'record', 'name' => 'ptr.unsynced.org.', 'type' => 'CNAME', 'content' => 'www.unsynced.org.', 'ttl' => 60, 'zone' => 'unsynced.org.'],
            ]);

        $records = $this->provider->findRecordsByName('www.unsynced.org');

        $this->assertSame([['www.unsynced.org', 'A']], array_map(static fn(array $r): array => [$r['name'], $r['type']], $records));
    }

    public function testAContentLookupThatFillsTheSearchLimitIsReportedAsIncomplete(): void
    {
        $hit = ['object_type' => 'record', 'name' => 'a.example.com.', 'type' => 'CNAME', 'content' => 'target.example.com.', 'ttl' => 60, 'zone' => 'example.com.'];
        $this->client->method('searchData')->willReturnCallback(
            static fn(string $query): array => $query === 'target.example.com.' ? array_fill(0, 10000, $hit) : []
        );

        $this->assertNull($this->provider->findAllRecordsByContent('target.example.com'));
        $this->assertCount(1, $this->provider->findRecordsByContent('target.example.com'), 'The partial result is still returned to callers that can live with it');
    }

    public function testAWildcardNameIsNotSentToTheSearchWhichWouldExpandIt(): void
    {
        $this->client->expects($this->never())->method('searchData');

        $this->assertSame([], $this->provider->findAllRecordsByContent('*.example.com'));
    }

    public function testANameIsAskedForInLowerCase(): void
    {
        $this->client->expects($this->once())->method('getZoneRrset')
            ->with('example.com.', 'www.example.com.', 'CNAME')
            ->willReturn(['name' => 'example.com.', 'rrsets' => [self::rrset('www.example.com.', 'CNAME', 'other.org.')]]);

        $records = $this->provider->findRecordsByName('WWW.Example.com', 'CNAME');

        $this->assertSame('www.example.com', $records[0]['name'], 'The record id is encoded from the stored lower-case name');
    }

    public function testAReverseViewVariantCanHoldThePtr(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type) VALUES (3, 3, '2.0.192.in-addr.arpa..internal', 'MASTER')");

        $this->assertSame(3, $this->provider->getBestMatchingReverseZoneId('5.2.0.192.in-addr.arpa'));
    }

    public function testTheBaseReverseZoneWinsOverItsViewVariant(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type) VALUES
            (3, 3, '2.0.192.in-addr.arpa..internal', 'MASTER'), (4, 4, '2.0.192.in-addr.arpa', 'MASTER')");

        $this->assertSame(4, $this->provider->getBestMatchingReverseZoneId('5.2.0.192.in-addr.arpa'));
    }

    public function testAContentLookupUnderTheLimitIsComplete(): void
    {
        $this->client->method('searchData')->willReturn([
            ['object_type' => 'record', 'name' => 'example.com.', 'type' => 'MX', 'content' => '10 mail.example.com.', 'ttl' => 60, 'zone' => 'example.com.'],
        ]);

        $records = $this->provider->findAllRecordsByContent('mail.example.com');

        $this->assertNotNull($records);
        $this->assertSame(['MX'], array_column($records, 'type'));
    }
}
