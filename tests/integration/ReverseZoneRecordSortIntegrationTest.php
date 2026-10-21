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
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;
use TestHelpers\FakeConfiguration;

/**
 * Issue #1657: records in an in-addr.arpa zone list in IP order, not by leading number.
 * Runs on SQLite, plus MySQL and PostgreSQL when the devcontainer is up.
 */
class ReverseZoneRecordSortIntegrationTest extends TestCase
{
    private const ZONE = '168.192.in-addr.arpa';

    /** Inserted out of order on purpose. */
    private const PTR_NAMES = [
        '9.10.168.192.in-addr.arpa',
        '_ptr.2.168.192.in-addr.arpa',
        '5.10.168.192.in-addr.arpa',
        '200.2.168.192.in-addr.arpa',
        '*.10.168.192.in-addr.arpa',
        '5.2.168.192.in-addr.arpa',
        '10.168.192.in-addr.arpa',
        '5.0/25.2.168.192.in-addr.arpa',
        '1.2.168.192.in-addr.arpa',
    ];

    private const EXPECTED_BODY = [
        '5.0/25.2.168.192.in-addr.arpa',
        '1.2.168.192.in-addr.arpa',
        '5.2.168.192.in-addr.arpa',
        '200.2.168.192.in-addr.arpa',
        '_ptr.2.168.192.in-addr.arpa',
        '10.168.192.in-addr.arpa',
        '5.10.168.192.in-addr.arpa',
        '9.10.168.192.in-addr.arpa',
        '*.10.168.192.in-addr.arpa',
    ];

    private static function scratchName(): string
    {
        return 'poweradmin_it_revsort_' . getmypid();
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private function connect(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        try {
            if ($engine === 'mysql') {
                $db = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', $options);
                $db->exec('DROP DATABASE IF EXISTS ' . self::scratchName());
                $db->exec('CREATE DATABASE ' . self::scratchName());
                $db->exec('USE ' . self::scratchName());
            } elseif ($engine === 'pgsql') {
                $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
                $db->exec('DROP SCHEMA IF EXISTS ' . self::scratchName() . ' CASCADE');
                $db->exec('CREATE SCHEMA ' . self::scratchName());
                $db->exec('SET search_path TO ' . self::scratchName());
            } else {
                $db = new PDO('sqlite::memory:', null, null, $options);
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        [$id, $flag, $off] = match ($engine) {
            'mysql' => ['INT AUTO_INCREMENT PRIMARY KEY', 'TINYINT(1) DEFAULT 0', '0'],
            'pgsql' => ['SERIAL PRIMARY KEY', "BOOL DEFAULT 'f'", "'f'"],
            default => ['INTEGER PRIMARY KEY', 'BOOLEAN DEFAULT 0', '0'],
        };
        $db->exec("CREATE TABLE domains (id $id, name VARCHAR(255), type VARCHAR(8))");
        $db->exec("CREATE TABLE records (id $id, domain_id INT, name VARCHAR(255), type VARCHAR(10),
            content VARCHAR(255), ttl INT, prio INT, disabled $flag, ordername VARCHAR(255), auth $flag)");
        $db->exec("CREATE TABLE comments (id $id, domain_id INT, name VARCHAR(255), type VARCHAR(10),
            modified_at INT, account VARCHAR(40), comment TEXT)");

        $db->exec("INSERT INTO domains (id, name, type) VALUES (1, '" . self::ZONE . "', 'NATIVE')");
        $insert = $db->prepare(
            "INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, auth) VALUES (1, :name, :type, :content, 3600, 0, $off, $off)"
        );
        foreach (self::PTR_NAMES as $name) {
            $insert->execute([':name' => $name, ':type' => 'PTR', ':content' => 'host.example.com']);
        }
        $insert->execute([':name' => self::ZONE, ':type' => 'NS', ':content' => 'ns1.example.com']);
        $insert->execute([':name' => self::ZONE, ':type' => 'SOA', ':content' => 'ns1.example.com hostmaster.example.com 1 3600 1800 1209600 3600']);
        $insert->execute([':name' => self::ZONE, ':type' => 'NS', ':content' => 'ns2.example.com']);

        return $db;
    }

    protected function tearDown(): void
    {
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::scratchName());
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::scratchName() . ' CASCADE');
        } catch (PDOException) {
        }
    }

    private function repository(PDO $db, string $engine): SqlRecordRepository
    {
        return new SqlRecordRepository($db, new FakeConfiguration(['database' => ['type' => $engine, 'pdns_db_name' => '']]));
    }

    /** @param iterable<mixed> $rows */
    private function names(iterable $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            $names[] = $row['name'];
        }

        return $names;
    }

    #[DataProvider('engines')]
    public function testTheApexLeadsAndTheBodyIsInIpOrder(string $engine): void
    {
        $rows = $this->repository($this->connect($engine), $engine)->getRecordsFromDomainId(1, 0, 100, 'name', 'ASC');

        $this->assertSame(['SOA', 'NS', 'NS'], array_map(fn($r) => $r['type'], array_slice($rows, 0, 3)));
        $this->assertSame(array_fill(0, 3, self::ZONE), $this->names(array_slice($rows, 0, 3)));
        $this->assertSame(self::EXPECTED_BODY, $this->names(array_slice($rows, 3)));
    }

    #[DataProvider('engines')]
    public function testDescendingReversesTheBodyAndKeepsTheApexOnTop(string $engine): void
    {
        $rows = $this->repository($this->connect($engine), $engine)->getRecordsFromDomainId(1, 0, 100, 'name', 'DESC');

        $this->assertSame('SOA', $rows[0]['type']);
        $this->assertSame(array_reverse(self::EXPECTED_BODY), $this->names(array_slice($rows, 3)));
    }

    #[DataProvider('engines')]
    public function testPagesFollowTheSameOrder(string $engine): void
    {
        $repo = $this->repository($this->connect($engine), $engine);

        $page = $repo->getRecordsFromDomainId(1, 3, 4, 'name', 'ASC');
        $this->assertSame(array_slice(self::EXPECTED_BODY, 0, 4), $this->names($page));
        $page = $repo->getRecordsFromDomainId(1, 7, 4, 'name', 'ASC');
        $this->assertSame(array_slice(self::EXPECTED_BODY, 4, 4), $this->names($page));
    }

    #[DataProvider('engines')]
    public function testFilteredListingUsesTheSameOrder(string $engine): void
    {
        $rows = $this->repository($this->connect($engine), $engine)->getFilteredRecords(1, 0, 100, 'name', 'ASC', false, '', 'PTR');

        $this->assertSame(self::EXPECTED_BODY, $this->names($rows));
    }

    #[DataProvider('engines')]
    public function testASlash24AndAClasslessZoneSortTheirHostLabels(string $engine): void
    {
        $db = $this->connect($engine);
        $db->exec("INSERT INTO domains (id, name, type) VALUES (3, '2.0.192.in-addr.arpa', 'NATIVE'), (4, '0/25.2.0.192.in-addr.arpa', 'NATIVE')");
        $db->exec("INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES
            (3, '10.2.0.192.in-addr.arpa', 'PTR', 'a', 3600, 0),
            (3, '2.0.192.in-addr.arpa', 'NS', 'ns1.example.com', 3600, 0),
            (3, '1e5.2.0.192.in-addr.arpa', 'PTR', 'b', 3600, 0),
            (3, '7.2.0.192.IN-ADDR.ARPA', 'PTR', 'c', 3600, 0),
            (3, '2.2.0.192.in-addr.arpa', 'PTR', 'd', 3600, 0),
            (4, '100.0/25.2.0.192.in-addr.arpa', 'PTR', 'e', 3600, 0),
            (4, '9.0/25.2.0.192.in-addr.arpa', 'PTR', 'f', 3600, 0),
            (4, '5.0/25.2.0.192.in-addr.arpa', 'PTR', 'g', 3600, 0)");
        $repo = $this->repository($db, $engine);

        // 1e5 reads as 1 on every engine: leading digits only
        $this->assertSame(
            ['2.0.192.in-addr.arpa', '1e5.2.0.192.in-addr.arpa', '2.2.0.192.in-addr.arpa', '7.2.0.192.IN-ADDR.ARPA', '10.2.0.192.in-addr.arpa'],
            $this->names($repo->getRecordsFromDomainId(3, 0, 100, 'name', 'ASC'))
        );
        $this->assertSame(
            ['5.0/25.2.0.192.in-addr.arpa', '9.0/25.2.0.192.in-addr.arpa', '100.0/25.2.0.192.in-addr.arpa'],
            $this->names($repo->getRecordsFromDomainId(4, 0, 100, 'name', 'ASC'))
        );
    }

    #[DataProvider('engines')]
    public function testAForwardZoneKeepsTheNaturalOrder(string $engine): void
    {
        $db = $this->connect($engine);
        $db->exec("INSERT INTO domains (id, name, type) VALUES (2, 'example.com', 'NATIVE')");
        $db->exec("INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES
            (2, '5.10.example.com', 'A', '192.0.2.1', 3600, 0),
            (2, '5.2.example.com', 'A', '192.0.2.2', 3600, 0),
            (2, '1.2.example.com', 'A', '192.0.2.3', 3600, 0)");

        $rows = $this->repository($db, $engine)->getRecordsFromDomainId(2, 0, 100, 'name', 'ASC');

        // Leading number only: 1, 5, 5, with the plain name as the tiebreak
        $this->assertSame(['1.2.example.com', '5.10.example.com', '5.2.example.com'], $this->names($rows));
    }
}
