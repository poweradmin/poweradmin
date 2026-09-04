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
 * Trac #460: when a search/type/content filter is applied to a reverse zone, PTR
 * records must remain sorted by the leading numeric octet rather than lexicographically.
 * Covers the getFilteredRecords() code path that bypassed SortHelper.
 */
class RecordRepositoryFilteredPtrSortingIntegrationTest extends TestCase
{
    private ?PDO $db = null;
    private int $domainId = 0;

    private const DB_HOST = '127.0.0.1';
    private const DB_PORT = '3306';
    private const DB_NAME = 'pdns';
    private const DB_USER = 'pdns';
    private const DB_PASS = 'poweradmin';

    private const PTR_OCTETS = [1, 10, 15, 20, 100, 251];

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

        $zoneName = uniqid('ptr-sort-', false) . '.2.0.192.in-addr.arpa';
        $stmt = $this->db->prepare("INSERT INTO domains (name, type) VALUES (:name, 'NATIVE')");
        $stmt->execute([':name' => $zoneName]);
        $this->domainId = (int)$this->db->lastInsertId();

        $insert = $this->db->prepare(
            "INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES (:domain_id, :name, 'PTR', :content, 3600, 0)"
        );
        // Scrambled so a lexicographic ORDER BY cannot land in numeric order by accident.
        $insertOrder = [10, 1, 100, 15, 251, 20];
        foreach ($insertOrder as $octet) {
            $insert->execute([
                ':domain_id' => $this->domainId,
                ':name' => "$octet.$zoneName",
                ':content' => "host$octet.example.com",
            ]);
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

    private function leadingOctets(array $records): array
    {
        return array_map(static function ($r): int {
            return (int)strstr($r['name'], '.', true);
        }, $records);
    }

    public function testPtrRecordsSortNumericallyWithTypeFilter(): void
    {
        $repo = $this->makeRepository();
        $records = $repo->getFilteredRecords($this->domainId, 0, 100, 'name', 'ASC', false, '', 'PTR');

        $this->assertCount(count(self::PTR_OCTETS), $records);
        $this->assertSame(self::PTR_OCTETS, $this->leadingOctets($records));
    }

    public function testPtrRecordsSortNumericallyWithContentFilter(): void
    {
        $repo = $this->makeRepository();
        $records = $repo->getFilteredRecords($this->domainId, 0, 100, 'name', 'ASC', false, '', '', 'example.com');

        $this->assertCount(count(self::PTR_OCTETS), $records);
        $this->assertSame(self::PTR_OCTETS, $this->leadingOctets($records));
    }

    public function testPtrRecordsSortNumericallyDescendingWithFilter(): void
    {
        $repo = $this->makeRepository();
        $records = $repo->getFilteredRecords($this->domainId, 0, 100, 'name', 'DESC', false, '', 'PTR');

        $this->assertCount(count(self::PTR_OCTETS), $records);
        $this->assertSame(array_reverse(self::PTR_OCTETS), $this->leadingOctets($records));
    }
}
