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

namespace Poweradmin\Tests\Unit\Infrastructure\Metrics;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Database\TableNameService;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Metrics\PoweradminMetricsCollector;
use Poweradmin\Version;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

#[CoversClass(PoweradminMetricsCollector::class)]
class PoweradminMetricsCollectorTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)');
        $this->db->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT)');
        $this->db->exec('CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, active INTEGER)');
        $this->db->exec('CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)');
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, active INTEGER)');

        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'a.example', 'MASTER'), (2, 'b.example', 'MASTER'), (3, 'c.example', 'NATIVE'), (4, 'd.example', 'SLAVE')");
        $this->db->exec('INSERT INTO records (domain_id, name) VALUES (1, "a"), (1, "b"), (3, "c")');
        // Zone 1 has two active keys, zone 2 only an inactive one
        $this->db->exec('INSERT INTO cryptokeys (domain_id, active) VALUES (1, 1), (1, 1), (2, 0)');
        $this->db->exec("INSERT INTO users (username, active) VALUES ('admin', 1), ('ops', 1), ('old', 0)");
    }

    private function collector(bool $apiBackend = false, ?PowerdnsApiClient $client = null, bool $countRecords = false): PoweradminMetricsCollector
    {
        return new PoweradminMetricsCollector(
            $this->db,
            new TableNameService(new FakeConfiguration(['database' => ['pdns_db_name' => '']])),
            $client,
            $apiBackend,
            $countRecords,
            new NullLogger()
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function byName(array $families): array
    {
        return array_column($families, null, 'name');
    }

    private static function samples(array $family): array
    {
        $out = [];
        foreach ($family['samples'] as $sample) {
            $out[json_encode($sample['labels'] ?? [])] = $sample['value'];
        }
        return $out;
    }

    public function testCountsFromTheDatabaseBackend(): void
    {
        $families = self::byName($this->collector()->collect());

        $this->assertSame(['{"type":"master"}' => 2, '{"type":"native"}' => 1, '{"type":"slave"}' => 1], self::samples($families['poweradmin_zones']));
        $this->assertSame(['[]' => 1], self::samples($families['poweradmin_zones_dnssec_signed']));
        $this->assertSame(['{"active":"true"}' => 2, '{"active":"false"}' => 1], self::samples($families['poweradmin_users']));
        $this->assertSame(['[]' => 1], self::samples($families['poweradmin_up']));
        $this->assertSame(Version::VERSION, $families['poweradmin_info']['samples'][0]['labels']['version']);
        $this->assertSame('sql', $families['poweradmin_info']['samples'][0]['labels']['backend']);
        $this->assertArrayHasKey('poweradmin_metrics_collect_duration_seconds', $families);
        $this->assertArrayNotHasKey('poweradmin_records', $families);
    }

    public function testRecordsAreOptIn(): void
    {
        $families = self::byName($this->collector(countRecords: true)->collect());

        $this->assertSame(['[]' => 3], self::samples($families['poweradmin_records']));
    }

    public function testApiBackendUsesOneZoneListAndNeverCountsRecords(): void
    {
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->expects($this->once())->method('getZoneCounts')->willReturn([
            'kinds' => ['native' => 5, 'master' => 2],
            'dnssec_signed' => 3,
        ]);

        $families = self::byName($this->collector(apiBackend: true, client: $client, countRecords: true)->collect());

        $this->assertSame(['{"type":"native"}' => 5, '{"type":"master"}' => 2], self::samples($families['poweradmin_zones']));
        $this->assertSame(['[]' => 3], self::samples($families['poweradmin_zones_dnssec_signed']));
        $this->assertSame('api', $families['poweradmin_info']['samples'][0]['labels']['backend']);
        $this->assertArrayNotHasKey('poweradmin_records', $families);
    }

    public function testUnreachablePowerDnsIsReportedAsDownWithoutZoneMetrics(): void
    {
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('getZoneCounts')->willReturn(null);

        $families = self::byName($this->collector(apiBackend: true, client: $client)->collect());

        $this->assertSame(['[]' => 0], self::samples($families['poweradmin_up']));
        $this->assertArrayNotHasKey('poweradmin_zones', $families);
        $this->assertArrayHasKey('poweradmin_users', $families);
    }

    public function testAPresignedZoneCountsAsSigned(): void
    {
        $this->db->exec("INSERT INTO domainmetadata (domain_id, kind, content) VALUES (3, 'PRESIGNED', '1')");

        $families = self::byName($this->collector()->collect());

        $this->assertSame(['[]' => 2], self::samples($families['poweradmin_zones_dnssec_signed']));
    }

    public function testAThrowingApiClientIsReportedAsDownInsteadOfFailingTheScrape(): void
    {
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('getZoneCounts')->willThrowException(new ApiErrorException('Connection refused', 0));

        $families = self::byName($this->collector(apiBackend: true, client: $client)->collect());

        $this->assertSame(['[]' => 0], self::samples($families['poweradmin_up']));
        $this->assertArrayHasKey('poweradmin_users', $families);
    }
}
