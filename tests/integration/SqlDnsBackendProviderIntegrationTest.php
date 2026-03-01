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
use Poweradmin\Infrastructure\Configuration\FakeConfiguration;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;

/**
 * Integration tests for the SQL DNS backend provider against a real database.
 *
 * Tests ensure the SQL provider produces correct queries against real MySQL/MariaDB.
 * Requires a running devcontainer with MariaDB.
 */
class SqlDnsBackendProviderIntegrationTest extends TestCase
{
    private ?PDOCommon $db = null;
    private ?SqlDnsBackendProvider $provider = null;
    private array $createdDomainIds = [];
    private array $createdSupermasters = [];

    private const DB_HOST = '127.0.0.1';
    private const DB_PORT = '3306';
    private const DB_NAME = 'pdns';
    private const DB_USER = 'pdns';
    private const DB_PASS = 'poweradmin';

    protected function setUp(): void
    {
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

        $config = new FakeConfiguration([
            'database' => [
                'pdns_db_name' => '',
            ],
        ]);

        $this->provider = new SqlDnsBackendProvider($this->db, $config);
    }

    protected function tearDown(): void
    {
        // Clean up created domains and their records
        foreach ($this->createdDomainIds as $id) {
            try {
                $this->db->exec("DELETE FROM records WHERE domain_id = $id");
                $this->db->exec("DELETE FROM domainmetadata WHERE domain_id = $id");
                $this->db->exec("DELETE FROM cryptokeys WHERE domain_id = $id");
                $this->db->exec("DELETE FROM domains WHERE id = $id");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up created supermasters
        foreach ($this->createdSupermasters as [$ip, $ns]) {
            try {
                $this->provider?->deleteSupermaster($ip, $ns);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        $this->db = null;
        $this->provider = null;
    }

    private function uniqueZoneName(): string
    {
        return 'sql-inttest-' . uniqid() . '.example.com';
    }

    // ---------------------------------------------------------------
    // Zone operations
    // ---------------------------------------------------------------

    public function testCreateNativeZone(): void
    {
        $zone = $this->uniqueZoneName();
        $domainId = $this->provider->createZone($zone, 'NATIVE');

        $this->assertIsInt($domainId);
        $this->assertGreaterThan(0, $domainId);
        $this->createdDomainIds[] = $domainId;

        $stmt = $this->db->prepare("SELECT name, type FROM domains WHERE id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals($zone, $row['name']);
        $this->assertEquals('NATIVE', $row['type']);
    }

    public function testCreateSlaveZoneWithMaster(): void
    {
        $zone = $this->uniqueZoneName();
        $domainId = $this->provider->createZone($zone, 'SLAVE', '10.0.0.1');

        $this->assertIsInt($domainId);
        $this->createdDomainIds[] = $domainId;

        $stmt = $this->db->prepare("SELECT type, master FROM domains WHERE id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('SLAVE', $row['type']);
        $this->assertEquals('10.0.0.1', $row['master']);
    }

    public function testDeleteZone(): void
    {
        $zone = $this->uniqueZoneName();
        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->createdDomainIds[] = $domainId;

        // Add a record so we can verify cascade
        $this->provider->addRecord($domainId, "www.$zone", 'A', '192.0.2.1', 3600, 0);

        $result = $this->provider->deleteZone($domainId, $zone);
        $this->assertTrue($result);

        // Verify domain gone
        $stmt = $this->db->prepare("SELECT id FROM domains WHERE id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $this->assertFalse($stmt->fetch());

        // Verify records gone
        $stmt = $this->db->prepare("SELECT id FROM records WHERE domain_id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $this->assertFalse($stmt->fetch());

        $this->createdDomainIds = array_diff($this->createdDomainIds, [$domainId]);
    }

    public function testUpdateZoneType(): void
    {
        $zone = $this->uniqueZoneName();
        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->createdDomainIds[] = $domainId;

        $this->assertTrue($this->provider->updateZoneType($domainId, 'MASTER'));

        $stmt = $this->db->prepare("SELECT type FROM domains WHERE id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $this->assertEquals('MASTER', $stmt->fetchColumn());
    }

    public function testUpdateZoneMaster(): void
    {
        $zone = $this->uniqueZoneName();
        $domainId = $this->provider->createZone($zone, 'SLAVE', '10.0.0.1');
        $this->createdDomainIds[] = $domainId;

        $this->assertTrue($this->provider->updateZoneMaster($domainId, '10.0.0.2'));

        $stmt = $this->db->prepare("SELECT master FROM domains WHERE id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $this->assertEquals('10.0.0.2', $stmt->fetchColumn());
    }

    // ---------------------------------------------------------------
    // Record operations
    // ---------------------------------------------------------------

    public function testAddRecord(): void
    {
        $zone = $this->uniqueZoneName();
        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->createdDomainIds[] = $domainId;

        $result = $this->provider->addRecord($domainId, "www.$zone", 'A', '192.0.2.1', 3600, 0);
        $this->assertTrue($result);

        $stmt = $this->db->prepare("SELECT name, type, content, ttl, prio FROM records WHERE domain_id = :id AND type = 'A'");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals("www.$zone", $row['name']);
        $this->assertEquals('192.0.2.1', $row['content']);
        $this->assertEquals(3600, (int)$row['ttl']);
    }

    public function testAddRecordGetId(): void
    {
        $zone = $this->uniqueZoneName();
        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->createdDomainIds[] = $domainId;

        $recordId = $this->provider->addRecordGetId($domainId, "test.$zone", 'AAAA', '2001:db8::1', 300, 0);
        $this->assertIsInt($recordId);
        $this->assertGreaterThan(0, $recordId);

        $stmt = $this->db->prepare("SELECT name, content FROM records WHERE id = :id");
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals("test.$zone", $row['name']);
        $this->assertEquals('2001:db8::1', $row['content']);
    }

    public function testEditRecord(): void
    {
        $zone = $this->uniqueZoneName();
        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->createdDomainIds[] = $domainId;

        $recordId = $this->provider->addRecordGetId($domainId, "edit.$zone", 'A', '192.0.2.1', 3600, 0);

        $this->assertTrue($this->provider->editRecord($recordId, "edit.$zone", 'A', '192.0.2.99', 7200, 0, 0));

        $stmt = $this->db->prepare("SELECT content, ttl FROM records WHERE id = :id");
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('192.0.2.99', $row['content']);
        $this->assertEquals(7200, (int)$row['ttl']);
    }

    public function testDeleteRecord(): void
    {
        $zone = $this->uniqueZoneName();
        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->createdDomainIds[] = $domainId;

        $recordId = $this->provider->addRecordGetId($domainId, "del.$zone", 'A', '192.0.2.1', 3600, 0);
        $this->assertTrue($this->provider->deleteRecord($recordId));

        $stmt = $this->db->prepare("SELECT id FROM records WHERE id = :id");
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
        $this->assertFalse($stmt->fetch());
    }

    public function testDeleteRecordsByDomainId(): void
    {
        $zone = $this->uniqueZoneName();
        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->createdDomainIds[] = $domainId;

        $this->provider->addRecord($domainId, "a.$zone", 'A', '192.0.2.1', 3600, 0);
        $this->provider->addRecord($domainId, "b.$zone", 'A', '192.0.2.2', 3600, 0);

        $this->assertTrue($this->provider->deleteRecordsByDomainId($domainId));

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM records WHERE domain_id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $this->assertEquals(0, (int)$stmt->fetchColumn());
    }

    // ---------------------------------------------------------------
    // Supermaster operations
    // ---------------------------------------------------------------

    public function testSupermasterCrud(): void
    {
        $ip = '10.77.77.' . rand(1, 254);
        $ns = 'ns-sqltest-' . uniqid() . '.example.com';
        $this->createdSupermasters[] = [$ip, $ns];

        $this->assertTrue($this->provider->addSupermaster($ip, $ns, 'testacct'));

        $list = $this->provider->getSupermasters();
        $found = false;
        foreach ($list as $entry) {
            if ($entry['master_ip'] === $ip && $entry['ns_name'] === $ns) {
                $this->assertEquals('testacct', $entry['account']);
                $found = true;
            }
        }
        $this->assertTrue($found, 'Supermaster should appear in list');

        // Update
        $this->assertTrue($this->provider->updateSupermaster($ip, $ns, $ip, $ns, 'newacct'));

        $list = $this->provider->getSupermasters();
        foreach ($list as $entry) {
            if ($entry['master_ip'] === $ip && $entry['ns_name'] === $ns) {
                $this->assertEquals('newacct', $entry['account']);
            }
        }

        // Delete
        $this->assertTrue($this->provider->deleteSupermaster($ip, $ns));

        $list = $this->provider->getSupermasters();
        foreach ($list as $entry) {
            $this->assertFalse(
                $entry['master_ip'] === $ip && $entry['ns_name'] === $ns,
                'Deleted supermaster should not appear'
            );
        }
    }

    // ---------------------------------------------------------------
    // Full lifecycle
    // ---------------------------------------------------------------

    public function testFullZoneLifecycle(): void
    {
        $zone = $this->uniqueZoneName();

        // Create
        $domainId = $this->provider->createZone($zone, 'NATIVE');
        $this->assertIsInt($domainId);
        $this->createdDomainIds[] = $domainId;

        // Add records
        $aId = $this->provider->addRecordGetId($domainId, "www.$zone", 'A', '192.0.2.1', 3600, 0);
        $this->provider->addRecord($domainId, "www.$zone", 'AAAA', '2001:db8::1', 3600, 0);
        $mxId = $this->provider->addRecordGetId($domainId, $zone, 'MX', 'mail.example.com.', 3600, 10);

        $this->assertIsInt($aId);
        $this->assertIsInt($mxId);

        // Edit
        $this->assertTrue($this->provider->editRecord($aId, "www.$zone", 'A', '198.51.100.1', 7200, 0, 0));

        // Verify
        $stmt = $this->db->prepare("SELECT content FROM records WHERE id = :id");
        $stmt->bindValue(':id', $aId, PDO::PARAM_INT);
        $stmt->execute();
        $this->assertEquals('198.51.100.1', $stmt->fetchColumn());

        // Delete single record
        $this->assertTrue($this->provider->deleteRecord($mxId));

        // Delete zone
        $this->assertTrue($this->provider->deleteZone($domainId, $zone));

        $stmt = $this->db->prepare("SELECT id FROM domains WHERE id = :id");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        $this->assertFalse($stmt->fetch());

        $this->createdDomainIds = array_diff($this->createdDomainIds, [$domainId]);
    }

    // ---------------------------------------------------------------
    // Capability
    // ---------------------------------------------------------------

    public function testIsNotApiBackend(): void
    {
        $this->assertFalse($this->provider->isApiBackend());
    }
}
