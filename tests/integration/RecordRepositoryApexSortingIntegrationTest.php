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
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;

/**
 * Integration test for SqlRecordRepository::getRecordsFromDomainId() apex pinning.
 *
 * Issue #1250: apex records (SOA, NS, name == zone name) must stay at the top of
 * the record list regardless of the user's chosen sort column or direction, in both
 * host-only and full-FQDN display modes (display mode runs after the SQL fetch).
 * @see https://github.com/poweradmin/poweradmin/issues/1250
 */
class RecordRepositoryApexSortingIntegrationTest extends TestCase
{
    private ?PDO $db = null;
    private int $domainId = 0;
    private array $createdRecordIds = [];

    private const DB_HOST = '127.0.0.1';
    private const DB_PORT = '3306';
    private const DB_NAME = 'pdns';
    private const DB_USER = 'pdns';
    private const DB_PASS = 'poweradmin';

    protected function setUp(): void
    {
        try {
            $this->db = new PDO(
                'mysql:host=' . self::DB_HOST . ';port=' . self::DB_PORT . ';dbname=' . self::DB_NAME,
                self::DB_USER,
                self::DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('MariaDB not available: ' . $e->getMessage());
        }

        $zoneName = 'apex-sort-' . uniqid() . '.example.com';
        $stmt = $this->db->prepare("INSERT INTO domains (name, type) VALUES (:name, 'NATIVE')");
        $stmt->execute([':name' => $zoneName]);
        $this->domainId = (int)$this->db->lastInsertId();

        $rows = [
            [$zoneName,            'SOA', 'ns1.example.com hostmaster.example.com 1 3600 1800 1209600 3600'],
            [$zoneName,            'NS',  'ns1.example.com'],
            [$zoneName,            'NS',  'ns2.example.com'],
            ['0test.' . $zoneName, 'A',   '10.0.0.1'],
            ['aaa.' . $zoneName,   'A',   '10.0.0.2'],
            ['www.' . $zoneName,   'A',   '10.0.0.3'],
            ['zzz.' . $zoneName,   'A',   '10.0.0.4'],
        ];
        $insert = $this->db->prepare(
            "INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES (:domain_id, :name, :type, :content, 3600, 0)"
        );
        foreach ($rows as [$name, $type, $content]) {
            $insert->execute([
                ':domain_id' => $this->domainId,
                ':name' => $name,
                ':type' => $type,
                ':content' => $content,
            ]);
            $this->createdRecordIds[] = (int)$this->db->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        if ($this->domainId > 0) {
            try {
                $this->db->exec("DELETE FROM records WHERE domain_id = {$this->domainId}");
                $this->db->exec("DELETE FROM domains WHERE id = {$this->domainId}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }
        $this->db = null;
    }

    private function makeRepository(): SqlRecordRepository
    {
        $config = new class implements ConfigurationInterface {
            public function get(string $group, string $key, mixed $default = null): mixed
            {
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                if ($group === 'database' && $key === 'pdns_db_name') {
                    return null;
                }
                return $default;
            }

            public function getGroup(string $group): array
            {
                return [];
            }

            public function getAll(): array
            {
                return [];
            }
        };

        return new SqlRecordRepository($this->db, $config);
    }

    private function recordOrder(array $records): array
    {
        return array_map(fn($r) => [$r['name'], $r['type']], $records);
    }

    public function testApexRecordsPinnedAtTopWhenSortingByNameAsc(): void
    {
        $repo = $this->makeRepository();
        $records = $repo->getRecordsFromDomainId('mysql', $this->domainId, 0, 100, 'name', 'ASC');

        $order = $this->recordOrder($records);
        $apexCount = 0;
        foreach (array_slice($order, 0, 3) as [$name, $type]) {
            $this->assertContains($type, ['SOA', 'NS'], "expected SOA/NS in first 3 rows, got $type for $name");
            $apexCount++;
        }
        $this->assertSame(3, $apexCount);
        $this->assertSame('A', $order[3][1], 'first non-apex row should be an A record');
    }

    public function testApexRecordsPinnedAtTopWhenSortingByNameDesc(): void
    {
        $repo = $this->makeRepository();
        $records = $repo->getRecordsFromDomainId('mysql', $this->domainId, 0, 100, 'name', 'DESC');

        $order = $this->recordOrder($records);
        foreach (array_slice($order, 0, 3) as [$name, $type]) {
            $this->assertContains($type, ['SOA', 'NS'], "apex must stay on top even when sorting DESC; got $type for $name");
        }
        // After apex block, names should descend
        $tailNames = array_column(array_slice($records, 3), 'name');
        $sortedTail = $tailNames;
        rsort($sortedTail);
        $this->assertSame($sortedTail, $tailNames, 'non-apex rows must be sorted DESC by name');
    }

    public function testApexRecordsPinnedAtTopWhenSortingByType(): void
    {
        $repo = $this->makeRepository();
        $records = $repo->getRecordsFromDomainId('mysql', $this->domainId, 0, 100, 'type', 'ASC');

        $order = $this->recordOrder($records);
        foreach (array_slice($order, 0, 3) as [$name, $type]) {
            $this->assertContains($type, ['SOA', 'NS'], "apex must stay on top when sorting by type; got $type for $name");
        }
    }

    public function testApexRecordsPinnedAtTopWhenSortingByContent(): void
    {
        $repo = $this->makeRepository();
        $records = $repo->getRecordsFromDomainId('mysql', $this->domainId, 0, 100, 'content', 'ASC');

        $order = $this->recordOrder($records);
        foreach (array_slice($order, 0, 3) as [$name, $type]) {
            $this->assertContains($type, ['SOA', 'NS'], "apex must stay on top when sorting by content; got $type for $name");
        }
    }
}
