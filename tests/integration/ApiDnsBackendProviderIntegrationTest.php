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

namespace integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\FakeConfiguration;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;

/**
 * Integration tests for the API DNS backend provider against a real PowerDNS instance.
 *
 * Requires a running devcontainer with PowerDNS and MariaDB.
 * Tests are skipped automatically if the services are unavailable.
 *
 * Run with: composer tests:integration
 */
class ApiDnsBackendProviderIntegrationTest extends TestCase
{
    private ?PDOCommon $db = null;
    private ?PowerdnsApiClient $client = null;
    private ?ApiDnsBackendProvider $provider = null;
    private array $createdZones = [];
    private array $createdAutoprimaries = [];

    private const PDNS_API_URL = 'http://localhost:8181';
    private const PDNS_API_KEY = 'fxiBmBFx7MITw5ECRMOr10ghlxGMvWZA';
    private const DB_HOST = '127.0.0.1';
    private const DB_PORT = '3306';
    private const DB_NAME = 'pdns';
    private const DB_USER = 'pdns';
    private const DB_PASS = 'poweradmin';

    protected function setUp(): void
    {
        // Try connecting to the database
        try {
            $this->db = new PDOCommon(
                'mysql:host=' . self::DB_HOST . ';port=' . self::DB_PORT . ';dbname=' . self::DB_NAME,
                self::DB_USER,
                self::DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('MariaDB not available: ' . $e->getMessage());
        }

        // Try connecting to the PowerDNS API
        $ch = @curl_init(self::PDNS_API_URL . '/api/v1/servers/localhost');
        if ($ch === false) {
            $this->markTestSkipped('curl not available');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . self::PDNS_API_KEY],
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            $this->markTestSkipped('PowerDNS API not available at ' . self::PDNS_API_URL);
        }

        $httpClient = new HttpClient(self::PDNS_API_URL, self::PDNS_API_KEY);
        $this->client = new PowerdnsApiClient($httpClient, 'localhost');

        $config = new FakeConfiguration([
            'pdns_api' => [
                'url' => self::PDNS_API_URL,
                'key' => self::PDNS_API_KEY,
                'server_name' => 'localhost',
                'backend' => 'api',
            ],
            'database' => [
                'pdns_db_name' => '',
            ],
        ]);

        $this->provider = new ApiDnsBackendProvider($this->client, $this->db, $config);
    }

    protected function tearDown(): void
    {
        // Clean up any zones created during tests
        foreach ($this->createdZones as $zoneName) {
            try {
                $apiName = str_ends_with($zoneName, '.') ? $zoneName : $zoneName . '.';
                $this->client?->deleteZone(new \Poweradmin\Domain\Model\Zone($apiName));
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up any autoprimaries created during tests
        foreach ($this->createdAutoprimaries as [$ip, $ns]) {
            try {
                $this->provider?->deleteSupermaster($ip, $ns);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        $this->db = null;
        $this->client = null;
        $this->provider = null;
    }

    private function uniqueZoneName(): string
    {
        return 'integration-test-' . uniqid() . '.example.com';
    }

    /**
     * Poll the DB until a specific record appears, using exponential backoff.
     * Returns the matching row or null on timeout.
     */
    private function waitForDbRecord(int $domainId, string $name, string $type, string $content, int $timeoutMs = 3000): ?array
    {
        $sleepUs = 50000; // 50ms initial
        $elapsedUs = 0;
        $limitUs = $timeoutMs * 1000;

        while ($elapsedUs < $limitUs) {
            $stmt = $this->db->prepare("SELECT id, content, ttl, prio FROM records WHERE domain_id = :did AND name = :name AND type = :type AND content = :content");
            $stmt->bindValue(':did', $domainId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->bindValue(':content', $content, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row !== false) {
                return $row;
            }

            usleep($sleepUs);
            $elapsedUs += $sleepUs;
            $sleepUs = min($sleepUs * 2, 500000);
        }

        return null;
    }

    /**
     * Poll the DB until the expected number of records of the given type appear.
     * Returns the content values array.
     */
    private function waitForDbRecordCount(int $domainId, string $name, string $type, int $expectedCount, int $timeoutMs = 3000): array
    {
        $sleepUs = 50000;
        $elapsedUs = 0;
        $limitUs = $timeoutMs * 1000;
        $records = [];

        while ($elapsedUs < $limitUs) {
            $stmt = $this->db->prepare("SELECT content FROM records WHERE domain_id = :did AND name = :name AND type = :type ORDER BY content");
            $stmt->bindValue(':did', $domainId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($records) === $expectedCount) {
                return $records;
            }

            usleep($sleepUs);
            $elapsedUs += $sleepUs;
            $sleepUs = min($sleepUs * 2, 500000);
        }

        return $records;
    }

    /**
     * Poll the DB until a record with given criteria is absent.
     */
    private function waitForDbRecordAbsent(int $domainId, string $name, string $type, string $content, int $timeoutMs = 3000): bool
    {
        $sleepUs = 50000;
        $elapsedUs = 0;
        $limitUs = $timeoutMs * 1000;

        while ($elapsedUs < $limitUs) {
            $stmt = $this->db->prepare("SELECT id FROM records WHERE domain_id = :did AND name = :name AND type = :type AND content = :content");
            $stmt->bindValue(':did', $domainId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->bindValue(':content', $content, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->fetch() === false) {
                return true;
            }

            usleep($sleepUs);
            $elapsedUs += $sleepUs;
            $sleepUs = min($sleepUs * 2, 500000);
        }

        return false;
    }

    // ---------------------------------------------------------------
    // Zone lifecycle tests
    // ---------------------------------------------------------------

    public function testCreateNativeZone(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');

        $this->assertIsInt($domainId);
        $this->assertGreaterThan(0, $domainId);

        // Verify zone exists in database
        $stmt = $this->db->prepare("SELECT name, type FROM domains WHERE id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertEquals($zone, $row['name']);
        $this->assertEquals('NATIVE', $row['type']);

        // Verify zone exists in API
        $apiZone = $this->client->getZone($zone . '.');
        $this->assertNotNull($apiZone);
        $this->assertEquals($zone . '.', $apiZone['name']);
    }

    public function testCreateSlaveZone(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'SLAVE', '192.168.1.1');

        $this->assertIsInt($domainId);
        $this->assertGreaterThan(0, $domainId);

        // Verify in API
        $apiZone = $this->client->getZone($zone . '.');
        $this->assertNotNull($apiZone);
        $this->assertEquals('Slave', $apiZone['kind']);
    }

    public function testDeleteZone(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);

        $result = $this->provider->deleteZone($domainId, $zone);
        $this->assertTrue($result);

        // Verify gone from API
        $apiZone = $this->client->getZone($zone . '.');
        $this->assertNull($apiZone);

        // Remove from cleanup list since already deleted
        $this->createdZones = array_diff($this->createdZones, [$zone]);
    }

    public function testUpdateZoneType(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);

        $result = $this->provider->updateZoneType($domainId, 'MASTER');
        $this->assertTrue($result);

        // Verify via API
        $apiZone = $this->client->getZone($zone . '.');
        $this->assertNotNull($apiZone);
        $this->assertEquals('Master', $apiZone['kind']);
    }

    // ---------------------------------------------------------------
    // Record lifecycle tests
    // ---------------------------------------------------------------

    public function testAddARecord(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);

        $result = $this->provider->addRecord($domainId, "www.$zone", 'A', '192.0.2.1', 3600, 0);
        $this->assertTrue($result);

        // Poll DB until record appears
        $row = $this->waitForDbRecord($domainId, "www.$zone", 'A', '192.0.2.1');
        $this->assertNotNull($row, 'A record should appear in DB');
        $this->assertEquals(3600, (int)$row['ttl']);
    }

    public function testAddMxRecord(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);

        $result = $this->provider->addRecord($domainId, $zone, 'MX', "mail.$zone.", 3600, 10);
        $this->assertTrue($result);

        // Verify the API stored content with priority prepended
        $apiZone = $this->client->getZone($zone . '.');
        $this->assertNotNull($apiZone);

        $mxFound = false;
        foreach ($apiZone['rrsets'] ?? [] as $rrset) {
            if ($rrset['type'] === 'MX') {
                foreach ($rrset['records'] as $record) {
                    if (str_contains($record['content'], "mail.$zone.")) {
                        $this->assertStringStartsWith('10 ', $record['content']);
                        $mxFound = true;
                    }
                }
            }
        }
        $this->assertTrue($mxFound, 'MX record not found in zone');
    }

    public function testAddMxRecordWithZeroPriority(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);

        $result = $this->provider->addRecord($domainId, $zone, 'MX', "mail.$zone.", 3600, 0);
        $this->assertTrue($result);

        $apiZone = $this->client->getZone($zone . '.');
        $mxFound = false;
        foreach ($apiZone['rrsets'] ?? [] as $rrset) {
            if ($rrset['type'] === 'MX') {
                foreach ($rrset['records'] as $record) {
                    if (str_contains($record['content'], "mail.$zone.")) {
                        $this->assertStringStartsWith('0 ', $record['content']);
                        $mxFound = true;
                    }
                }
            }
        }
        $this->assertTrue($mxFound, 'MX record with priority 0 not found');
    }

    public function testAddRecordGetId(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);

        $recordId = $this->provider->addRecordGetId($domainId, "test.$zone", 'A', '192.0.2.2', 300, 0);
        $this->assertIsInt($recordId);
        $this->assertGreaterThan(0, $recordId);

        // Verify the returned ID points to the correct record
        $stmt = $this->db->prepare("SELECT name, content FROM records WHERE id = :id");
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertEquals("test.$zone", $row['name']);
        $this->assertEquals('192.0.2.2', $row['content']);
    }

    public function testAddMultipleRecordsSameRRset(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);

        // Add two A records for the same name
        $result1 = $this->provider->addRecord($domainId, "multi.$zone", 'A', '192.0.2.1', 3600, 0);
        $this->assertTrue($result1);

        $result2 = $this->provider->addRecord($domainId, "multi.$zone", 'A', '192.0.2.2', 3600, 0);
        $this->assertTrue($result2);

        // Poll DB until both records appear
        $records = $this->waitForDbRecordCount($domainId, "multi.$zone", 'A', 2);

        $this->assertCount(2, $records);
        $this->assertContains('192.0.2.1', $records);
        $this->assertContains('192.0.2.2', $records);
    }

    public function testEditRecord(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);

        // Add record
        $recordId = $this->provider->addRecordGetId($domainId, "edit.$zone", 'A', '192.0.2.1', 3600, 0);
        $this->assertIsInt($recordId);

        // Edit it
        $result = $this->provider->editRecord($recordId, "edit.$zone", 'A', '192.0.2.99', 7200, 0, 0);
        $this->assertTrue($result);

        // Poll DB until edited record appears (REPLACE creates new record IDs)
        $row = $this->waitForDbRecord($domainId, "edit.$zone", 'A', '192.0.2.99');
        $this->assertNotNull($row, 'Edited A record should appear in DB');
        $this->assertEquals(7200, (int)$row['ttl']);
    }

    public function testDeleteRecord(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);

        // Add record
        $recordId = $this->provider->addRecordGetId($domainId, "del.$zone", 'A', '192.0.2.1', 3600, 0);
        $this->assertIsInt($recordId);

        // Delete it
        $result = $this->provider->deleteRecord($recordId);
        $this->assertTrue($result);

        // Poll DB until record disappears
        $this->assertTrue(
            $this->waitForDbRecordAbsent($domainId, "del.$zone", 'A', '192.0.2.1'),
            'Deleted record should disappear from DB'
        );
    }

    public function testDeleteOneRecordFromRRset(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);

        // Add two records to the same RRset
        $this->provider->addRecordGetId($domainId, "rrset.$zone", 'A', '192.0.2.1', 3600, 0);
        $this->provider->addRecordGetId($domainId, "rrset.$zone", 'A', '192.0.2.2', 3600, 0);

        // PowerDNS REPLACE invalidates old record IDs. Re-lookup by content.
        $row = $this->waitForDbRecord($domainId, "rrset.$zone", 'A', '192.0.2.1');
        $this->assertNotNull($row, 'First A record should exist in DB');
        $currentId1 = (int)$row['id'];

        // Delete first, second should remain
        $result = $this->provider->deleteRecord($currentId1);
        $this->assertTrue($result);

        // Poll DB until only one record remains
        $records = $this->waitForDbRecordCount($domainId, "rrset.$zone", 'A', 1);

        $this->assertCount(1, $records);
        $this->assertEquals('192.0.2.2', $records[0]);
    }

    // ---------------------------------------------------------------
    // Full lifecycle test
    // ---------------------------------------------------------------

    public function testFullZoneLifecycle(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        // 1. Create zone
        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId, 'Zone creation should return domain ID');

        // 2. Add A record
        $aRecordId = $this->provider->addRecordGetId($domainId, "www.$zone", 'A', '198.51.100.1', 3600, 0);
        $this->assertIsInt($aRecordId, 'A record should be created and ID returned');

        // 3. Add AAAA record
        $this->assertTrue(
            $this->provider->addRecord($domainId, "www.$zone", 'AAAA', '2001:db8::1', 3600, 0),
            'AAAA record should be added successfully'
        );

        // 4. Add CNAME
        $this->assertTrue(
            $this->provider->addRecord($domainId, "alias.$zone", 'CNAME', "www.$zone.", 3600, 0),
            'CNAME record should be added successfully'
        );

        // 5. Edit A record - re-lookup current ID since REPLACE invalidates old IDs
        $row = $this->waitForDbRecord($domainId, "www.$zone", 'A', '198.51.100.1');
        $this->assertNotNull($row, 'A record should exist before edit');
        $currentAId = (int)$row['id'];

        $this->assertTrue(
            $this->provider->editRecord($currentAId, "www.$zone", 'A', '198.51.100.2', 7200, 0, 0),
            'A record should be edited successfully'
        );

        // Verify edit - poll by content since REPLACE creates new record IDs
        $editedRow = $this->waitForDbRecord($domainId, "www.$zone", 'A', '198.51.100.2');
        $this->assertNotNull($editedRow, 'Edited A record should appear in DB');
        $this->assertEquals(7200, (int)$editedRow['ttl']);

        // 6. Delete CNAME - PowerDNS stores CNAME content without trailing dot in DB
        $cnameRow = $this->waitForDbRecord($domainId, "alias.$zone", 'CNAME', "www.$zone");
        $this->assertNotNull($cnameRow, 'CNAME record should exist');

        $this->assertTrue(
            $this->provider->deleteRecord((int)$cnameRow['id']),
            'CNAME record should be deleted'
        );

        // 7. Verify remaining records - poll until CNAME is gone
        $this->assertTrue(
            $this->waitForDbRecordAbsent($domainId, "alias.$zone", 'CNAME', "www.$zone"),
            'CNAME should be gone from DB'
        );

        $stmt = $this->db->prepare("SELECT type, content FROM records WHERE domain_id = :did AND type IN ('A', 'AAAA', 'CNAME') ORDER BY type");
        $stmt->bindValue(':did', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $remaining = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $types = array_column($remaining, 'type');
        $this->assertContains('A', $types);
        $this->assertContains('AAAA', $types);
        $this->assertNotContains('CNAME', $types);

        // 8. Delete zone
        $this->assertTrue(
            $this->provider->deleteZone($domainId, $zone),
            'Zone should be deleted'
        );

        $apiZone = $this->client->getZone($zone . '.');
        $this->assertNull($apiZone, 'Zone should be gone from API');

        $this->createdZones = array_diff($this->createdZones, [$zone]);
    }

    // ---------------------------------------------------------------
    // SOA serial (no-op in API mode)
    // ---------------------------------------------------------------

    public function testUpdateSOASerialIsNoOp(): void
    {
        $zone = $this->uniqueZoneName();
        $this->createdZones[] = $zone;

        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);

        // Should always return true without doing anything
        $this->assertTrue($this->provider->updateSOASerial($domainId));
    }

    // ---------------------------------------------------------------
    // Capability check
    // ---------------------------------------------------------------

    public function testIsApiBackend(): void
    {
        $this->assertTrue($this->provider->isApiBackend());
    }

    // ---------------------------------------------------------------
    // Autoprimary (supermaster) tests
    // ---------------------------------------------------------------

    public function testAutoprimaryCrud(): void
    {
        $ip = '10.99.99.' . rand(1, 254);
        $ns = 'ns-inttest-' . uniqid() . '.example.com';
        $this->createdAutoprimaries[] = [$ip, $ns];

        // Add
        $this->assertTrue($this->provider->addSupermaster($ip, $ns, 'testaccount'));

        // List
        $list = $this->provider->getSupermasters();
        $found = false;
        foreach ($list as $entry) {
            if ($entry['master_ip'] === $ip && $entry['ns_name'] === $ns) {
                $this->assertEquals('testaccount', $entry['account']);
                $found = true;
            }
        }
        $this->assertTrue($found, 'Added autoprimary should appear in list');

        // Delete
        $this->assertTrue($this->provider->deleteSupermaster($ip, $ns));

        // Verify gone
        $list = $this->provider->getSupermasters();
        foreach ($list as $entry) {
            $this->assertFalse(
                $entry['master_ip'] === $ip && $entry['ns_name'] === $ns,
                'Deleted autoprimary should not appear in list'
            );
        }
    }

    public function testUpdateSupermasterChangesIp(): void
    {
        $oldIp = '10.88.88.' . rand(1, 254);
        $newIp = '10.88.88.' . rand(1, 254);
        while ($newIp === $oldIp) {
            $newIp = '10.88.88.' . rand(1, 254);
        }
        $ns = 'ns-update-' . uniqid() . '.example.com';
        $this->createdAutoprimaries[] = [$oldIp, $ns];

        $this->assertTrue($this->provider->addSupermaster($oldIp, $ns, 'acct'));

        $this->assertTrue($this->provider->updateSupermaster($oldIp, $ns, $newIp, $ns, 'acct'));
        // Track new entry for cleanup (old was deleted by update)
        $this->createdAutoprimaries[] = [$newIp, $ns];

        // Old should be gone, new should exist
        $list = $this->provider->getSupermasters();
        $foundOld = false;
        $foundNew = false;
        foreach ($list as $entry) {
            if ($entry['master_ip'] === $oldIp && $entry['ns_name'] === $ns) {
                $foundOld = true;
            }
            if ($entry['master_ip'] === $newIp && $entry['ns_name'] === $ns) {
                $foundNew = true;
            }
        }
        $this->assertFalse($foundOld, 'Old autoprimary should be removed');
        $this->assertTrue($foundNew, 'New autoprimary should exist');
    }
}
