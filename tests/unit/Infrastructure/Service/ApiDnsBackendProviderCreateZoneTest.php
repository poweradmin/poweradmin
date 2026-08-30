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
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * createZone() writes the zones row and backfills its domain_id. Committed apart, an
 * interrupted request strands the row at a domain_id that no canonical read resolves,
 * and nothing in the application repairs it. These run against a real PDO because a
 * mocked one cannot show what the database actually ends up holding.
 */
class ApiDnsBackendProviderCreateZoneTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec(
            "CREATE TABLE zones (
                id INTEGER PRIMARY KEY,
                domain_id INTEGER NULL DEFAULT NULL,
                owner INTEGER NULL DEFAULT NULL,
                zone_templ_id INTEGER NOT NULL DEFAULT 0,
                zone_name TEXT,
                zone_type TEXT,
                zone_master TEXT
            )"
        );
    }

    private function provider(?PDO $db = null): ApiDnsBackendProvider
    {
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('createZoneWithData')->willReturn(['name' => 'example.com.']);

        return new ApiDnsBackendProvider(
            $client,
            $db ?? $this->db,
            $this->createMock(ConfigurationInterface::class),
            new NullLogger()
        );
    }

    private function row(int $id): array
    {
        $stmt = $this->db->prepare("SELECT id, domain_id, zone_name FROM zones WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function testCreatedZoneRowCarriesItsOwnIdAsDomainId(): void
    {
        $zoneId = $this->provider()->createZone('example.com', 'MASTER');

        $this->assertGreaterThan(0, $zoneId);
        $row = $this->row($zoneId);
        $this->assertSame($zoneId, (int)$row['domain_id']);
        $this->assertNotNull($row['domain_id'], 'domain_id must not be left NULL');
        $this->assertNotSame(0, (int)$row['domain_id'], 'domain_id must not be left at 0');
    }

    public function testNoZonesRowSurvivesAFailedBackfill(): void
    {
        // The regression: the insert used to autocommit on its own, so a failure here left
        // a row stranded at domain_id 0 forever.
        $db = new class ('sqlite::memory:') extends PDO {
            public bool $failUpdates = false;

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if ($this->failUpdates && str_starts_with($query, 'UPDATE zones SET domain_id')) {
                    throw new RuntimeException('connection lost');
                }
                return parent::prepare($query, $options);
            }
        };
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, owner INTEGER NULL,
             zone_templ_id INTEGER NOT NULL DEFAULT 0, zone_name TEXT, zone_type TEXT, zone_master TEXT)"
        );
        $db->failUpdates = true;

        try {
            $this->provider($db)->createZone('example.com', 'MASTER');
            $this->fail('Expected the backfill failure to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('connection lost', $e->getMessage());
        }

        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM zones")->fetchColumn());
        $this->assertFalse($db->inTransaction(), 'transaction left open after rollback');
    }

    public function testLeavesNoTransactionOpenOnSuccess(): void
    {
        $this->provider()->createZone('example.com', 'MASTER');

        $this->assertFalse($this->db->inTransaction());
    }

    public function testDoesNotCommitATransactionItDidNotOpen(): void
    {
        $this->db->beginTransaction();

        $zoneId = $this->provider()->createZone('example.com', 'MASTER');

        $this->assertTrue($this->db->inTransaction(), 'the caller still owns its transaction');
        $this->db->rollBack();
        $this->assertSame([], $this->row($zoneId), 'the row must roll back with the caller');
    }

    public function testExistingZoneRowIsUpdatedRatherThanReinserted(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (9, 9, 'example.com')");

        $zoneId = $this->provider()->createZone('example.com', 'SLAVE', '10.0.0.1');

        $this->assertSame(9, $zoneId);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM zones")->fetchColumn());
        $this->assertFalse($this->db->inTransaction());
    }
}
