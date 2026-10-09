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

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Repository\ApiRecordSearch;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * Comment search over a real PowerDNS API: the RRset's records come back only
 * when the comments option is on. Uses the API-backend instance's PowerDNS
 * (127.0.0.1:8186) and a throwaway zone; skipped when it is unreachable.
 */
class ApiRecordSearchCommentsIntegrationTest extends TestCase
{
    private const PDNS_API_URL = 'http://127.0.0.1:8186';
    private const PDNS_API_KEY = 'fxiBmBFx7MITw5ECRMOr10ghlxGMvWZA';

    private ?PowerdnsApiClient $client = null;
    private ?PDO $db = null;
    private string $zone = '';

    protected function setUp(): void
    {
        $ch = @curl_init(self::PDNS_API_URL . '/api/v1/servers/localhost');
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
            $this->markTestSkipped('PowerDNS API not available at ' . self::PDNS_API_URL);
        }

        $this->client = new PowerdnsApiClient(new HttpClient(self::PDNS_API_URL, self::PDNS_API_KEY), 'localhost');

        $this->zone = 'comment-search-' . uniqid() . '.example.com';
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT, owner INTEGER, comment TEXT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner, comment) VALUES (1, 1, '{$this->zone}', 0, '')");
    }

    protected function tearDown(): void
    {
        if ($this->client !== null && $this->zone !== '') {
            $this->client->deleteZone(new Zone($this->zone . '.'));
        }
        $this->client = null;
        $this->db = null;
    }

    private function search(bool $recordCommentsEnabled): ApiRecordSearch
    {
        $provider = new ApiDnsBackendProvider(
            $this->client,
            $this->db,
            new FakeConfiguration(['pdns_api' => ['backend' => 'api'], 'database' => ['pdns_db_name' => '']]),
            new NullLogger()
        );
        $zones = $this->createStub(ZoneRepositoryInterface::class);

        return new ApiRecordSearch($this->db, $provider, $zones, $recordCommentsEnabled);
    }

    private function parameters(string $query, bool $comments): array
    {
        return ['query' => $query, 'zones' => false, 'records' => true, 'comments' => $comments, 'wildcard' => true, 'reverse' => false];
    }

    public function testRrsetRecordsMatchOnlyWhenCommentsAreSearched(): void
    {
        $marker = 'cmtneedle' . bin2hex(random_bytes(4));
        $created = $this->client->createZoneWithData([
            'name' => $this->zone . '.',
            'kind' => 'Native',
            'nameservers' => ['ns1.' . $this->zone . '.'],
            'rrsets' => [
                [
                    'name' => 'www.' . $this->zone . '.',
                    'type' => 'A',
                    'ttl' => 60,
                    'changetype' => 'REPLACE',
                    'records' => [['content' => '192.0.2.10', 'disabled' => false], ['content' => '192.0.2.11', 'disabled' => false]],
                    'comments' => [['content' => 'note ' . $marker, 'account' => 'it']],
                ],
                [
                    'name' => 'other.' . $this->zone . '.',
                    'type' => 'A',
                    'ttl' => 60,
                    'changetype' => 'REPLACE',
                    'records' => [['content' => '192.0.2.12', 'disabled' => false]],
                ],
            ],
        ]);
        $this->assertNotNull($created, 'throwaway zone created');

        $with = $this->search(true);
        $rows = $with->searchRecords($this->parameters($marker, true), 'all', null, 'content', 'ASC', false, 50, false, 1);
        $this->assertSame(['192.0.2.10', '192.0.2.11'], array_column($rows, 'content'));
        $this->assertSame(2, $with->getTotalRecords($this->parameters($marker, true), 'all', null, false));

        $this->assertSame([], $with->searchRecords($this->parameters($marker, false), 'all', null, 'content', 'ASC', false, 50, false, 1));
        $this->assertSame(0, $with->getTotalRecords($this->parameters($marker, false), 'all', null, false));

        $disabled = $this->search(false);
        $this->assertSame([], $disabled->searchRecords($this->parameters($marker, true), 'all', null, 'content', 'ASC', false, 50, false, 1));
    }
}
