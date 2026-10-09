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

use Poweradmin\Infrastructure\Database\PdoTransaction;
use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;
use TestHelpers\FlakyMarkerLockPdo;
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
                comment TEXT NULL,
                zone_templ_id INTEGER NOT NULL DEFAULT 0,
                zone_name TEXT,
                zone_type TEXT,
                zone_master TEXT
            )"
        );
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER)");
        $this->db->exec("CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, value_type TEXT NOT NULL DEFAULT 'string')");
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

    public function testANewZoneNeverTakesAMigratedZonesId(): void
    {
        // Row 1 was migrated from SQL mode with domain_id 2, so the next row id is a taken zone id
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (1, 2, 'migrated.example')");

        $zoneId = $this->provider()->createZone('example.com', 'MASTER');

        $this->assertSame(3, $zoneId);
        $this->assertSame(['id' => 3, 'domain_id' => 3], array_map('intval', $this->db->query("SELECT id, domain_id FROM zones WHERE zone_name = 'example.com'")->fetch(PDO::FETCH_ASSOC)));
        $this->assertSame(4, $this->provider()->createZone('next.example.com', 'MASTER'), 'The next zone keeps its own id');
    }

    public function testAnExistingMigratedRowIsReturnedByItsCanonicalId(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (7, 4011, 'example.com')");

        $this->assertSame(4011, $this->provider()->createZone('example.com', 'MASTER'));
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
            "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, owner INTEGER NULL, comment TEXT NULL,
             zone_templ_id INTEGER NOT NULL DEFAULT 0, zone_name TEXT, zone_type TEXT, zone_master TEXT)"
        );
        $db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $db->exec("CREATE TABLE api_key_zones (id INTEGER PRIMARY KEY, api_key_id INTEGER, zone_id INTEGER)");
        $db->exec("CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, value_type TEXT NOT NULL DEFAULT 'string')");
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

        $this->assertFalse((new PdoTransaction($this->db))->inTransaction());
    }

    public function testDoesNotCommitATransactionItDidNotOpen(): void
    {
        $this->db->beginTransaction();

        $zoneId = $this->provider()->createZone('example.com', 'MASTER');

        $this->assertTrue((new PdoTransaction($this->db))->inTransaction(), 'the caller still owns its transaction');
        $this->db->rollBack();
        $this->assertSame([], $this->row($zoneId), 'the row must roll back with the caller');
    }

    public function testExistingZoneRowIsUpdatedRatherThanReinserted(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (9, 9, 'example.com')");

        $zoneId = $this->provider()->createZone('example.com', 'SLAVE', '10.0.0.1');

        $this->assertSame(9, $zoneId);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM zones")->fetchColumn());
        $this->assertFalse((new PdoTransaction($this->db))->inTransaction());
    }

    public function testARowAdoptedByAConcurrentSyncBeforeTheMarkerLockIsSettledOnNotInserted(): void
    {
        // The sync commits its row after createZone() starts but before the allocator holds the marker lock
        $db = new class ('sqlite::memory:') extends PDO {
            public bool $adopted = false;

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (!$this->adopted && str_starts_with($query, 'SELECT setting_value FROM app_settings')) {
                    $this->adopted = true;
                    $this->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type) VALUES (5, 5, 'example.com', 'NATIVE')");
                }
                return parent::prepare($query, $options);
            }
        };
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($this->db->query("SELECT sql FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN) as $ddl) {
            $db->exec($ddl);
        }
        $db->exec("CREATE UNIQUE INDEX idx_zones_zone_name ON zones (zone_name)");
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('createZoneWithData')->willReturn(['name' => 'example.com.']);
        $client->expects($this->never())->method('deleteZone');

        $zoneId = $this->providerWithClient($client, $db)->createZone('example.com', 'MASTER');

        $this->assertSame(5, $zoneId);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM zones")->fetchColumn());
        $this->assertSame('MASTER', $db->query("SELECT zone_type FROM zones WHERE id = 5")->fetchColumn());
        $this->assertFalse($db->inTransaction());
    }

    /** A connection whose first N attempts at the marker lock fail as a deadlock victim, or always with another error. */
    private function flakyDb(int $deadlocks, ?string $alwaysFail = null): FlakyMarkerLockPdo
    {
        $db = new FlakyMarkerLockPdo('sqlite::memory:');
        $db->deadlocks = $deadlocks;
        $db->alwaysFail = $alwaysFail;
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($this->db->query("SELECT sql FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN) as $ddl) {
            $db->exec($ddl);
        }

        return $db;
    }

    private function providerWithClient(PowerdnsApiClient $client, PDO $db, ?\Psr\Log\LoggerInterface $logger = null): ApiDnsBackendProvider
    {
        return new ApiDnsBackendProvider($client, $db, $this->createMock(ConfigurationInterface::class), $logger ?? new NullLogger());
    }

    public function testADeadlockVictimIsRetriedAndNoZoneIsRemovedFromPowerDns(): void
    {
        $db = $this->flakyDb(2);
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('createZoneWithData')->willReturn(['name' => 'example.com.']);
        $client->expects($this->never())->method('deleteZone');

        $zoneId = $this->providerWithClient($client, $db)->createZone('example.com', 'MASTER');

        $this->assertGreaterThan(0, $zoneId);
        $this->assertSame(3, $db->attempts);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM zones WHERE zone_name = 'example.com'")->fetchColumn());
        $this->assertFalse($db->inTransaction());
    }

    public function testTheZoneIsRemovedFromPowerDnsWhenItsLocalRowKeepsFailing(): void
    {
        $db = $this->flakyDb(0, 'disk is full');
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('createZoneWithData')->willReturn(['name' => 'example.com.']);
        $deleted = [];
        $client->expects($this->once())->method('deleteZone')->willReturnCallback(function ($zone) use (&$deleted): bool {
            $deleted[] = $zone->getName();

            return true;
        });
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('could not be stored'),
            $this->callback(static fn(array $context): bool => $context['class'] === \PDOException::class && str_contains($context['error'], 'disk is full'))
        );

        try {
            $this->providerWithClient($client, $db, $logger)->createZone('example.com', 'MASTER');
            $this->fail('Expected the local failure to propagate');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('disk is full', $e->getMessage());
        }

        $this->assertSame(['example.com.'], $deleted);
        $this->assertSame(1, $db->attempts, 'A failure that is not a lock conflict is not retried');
    }

    public function testAZoneStillFailingAfterEveryRetryIsRemovedFromPowerDns(): void
    {
        $db = $this->flakyDb(100);
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('createZoneWithData')->willReturn(['name' => 'example.com.']);
        $client->expects($this->once())->method('deleteZone')->willReturn(true);

        try {
            $this->providerWithClient($client, $db)->createZone('example.com', 'MASTER');
            $this->fail('Expected the deadlock to propagate once the retries ran out');
        } catch (\PDOException $e) {
            $this->assertSame('40001', $e->errorInfo[0]);
        }

        $this->assertSame(\Poweradmin\Infrastructure\Database\DeadlockRetry::MAX_ATTEMPTS, $db->attempts);
    }

    public function testAFailedCleanupDoesNotHideTheOriginalError(): void
    {
        $db = $this->flakyDb(0, 'disk is full');
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('createZoneWithData')->willReturn(['name' => 'example.com.']);
        $client->method('deleteZone')->willThrowException(new RuntimeException('pdns down'));
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('error');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('disk is full');
        $this->providerWithClient($client, $db, $logger)->createZone('example.com', 'MASTER');
    }
}
