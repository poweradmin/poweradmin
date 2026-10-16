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
use Poweradmin\Application\Query\ZoneSearch;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Zone search pages over zones, not over the rows the owner join produces. A zone with
 * three owners is three joined rows, so paging those rows gave short pages and split zones
 * across pages (#1736).
 *
 * Runs against a per-process scratch database on each engine; MySQL and PostgreSQL need
 * the devcontainer and are skipped without it.
 */
class ZoneSearchPagingIntegrationTest extends TestCase
{
    private const ZONES = 30;
    private const PER_PAGE = 10;

    private static string $scratch = '';
    private PDO $db;
    private string $engine;

    protected function setUp(): void
    {
        self::$scratch = 'poweradmin_it_zs_' . getmypid();
    }

    protected function tearDown(): void
    {
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::$scratch);
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::$scratch . ' CASCADE');
        } catch (PDOException) {
        }
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql'], 'sqlite' => ['sqlite']];
    }

    /**
     * Zones zone01..zone30.example, each owned by users 1, 2 and 3. User 2 is the only
     * owner of the first twelve on their own rows too; zone05 carries a comment.
     */
    private function createScratch(string $engine): void
    {
        $this->engine = $engine;
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        try {
            if ($engine === 'mysql') {
                $root = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', $options);
                $root->exec('DROP DATABASE IF EXISTS ' . self::$scratch);
                $root->exec('CREATE DATABASE ' . self::$scratch);
                $this->db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . self::$scratch, 'root', 'uberuser', $options);
            } elseif ($engine === 'pgsql') {
                $this->db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
                $this->db->exec('DROP SCHEMA IF EXISTS ' . self::$scratch . ' CASCADE');
                $this->db->exec('CREATE SCHEMA ' . self::$scratch);
                $this->db->exec('SET search_path TO ' . self::$scratch);
            } else {
                $this->db = new PDO('sqlite::memory:', null, null, $options);
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        $this->db->exec('CREATE TABLE domains (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL, type VARCHAR(8) NOT NULL)');
        $this->db->exec('CREATE TABLE records (id INT PRIMARY KEY, domain_id INT NOT NULL, type VARCHAR(10) NULL)');
        $this->db->exec('CREATE TABLE zones (id INT PRIMARY KEY, domain_id INT NOT NULL, owner INT NULL, comment VARCHAR(1024) NULL, zone_templ_id INT NOT NULL DEFAULT 0)');
        $this->db->exec('CREATE TABLE users (id INT PRIMARY KEY, username VARCHAR(64) NOT NULL, fullname VARCHAR(255) NOT NULL)');
        $this->db->exec('CREATE TABLE zones_groups (id INT PRIMARY KEY, domain_id INT NOT NULL, group_id INT NOT NULL)');
        $this->db->exec('CREATE TABLE user_group_members (id INT PRIMARY KEY, user_id INT NOT NULL, group_id INT NOT NULL)');

        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (1, 'admin', 'Alice'), (2, 'manager', 'Bob'), (3, 'client', 'Carol')");
        $zoneRow = 1;
        $recordRow = 1;
        for ($i = 1; $i <= self::ZONES; $i++) {
            $name = sprintf('zone%02d.example', $i);
            $type = $i % 2 === 0 ? 'NATIVE' : 'MASTER';
            $this->db->exec("INSERT INTO domains (id, name, type) VALUES ($i, '$name', '$type')");
            $owners = $i <= 12 ? [1, 2, 3] : [1, 3];
            foreach ($owners as $owner) {
                $comment = $i === 5 ? "'needle in zone five'" : 'NULL';
                $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment) VALUES ($zoneRow, $i, $owner, $comment)");
                $zoneRow++;
            }
            // Zone i has i records, so the record-count sort has a known order
            for ($r = 0; $r < $i; $r++) {
                $this->db->exec("INSERT INTO records (id, domain_id, type) VALUES ($recordRow, $i, 'A')");
                $recordRow++;
            }
        }
    }

    private function search(int $userId = 1): ZoneSearch
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $group === 'database' && $key === 'type' ? $this->engine : $default
        );
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn($userId);

        return new ZoneSearch($this->db, $config, $this->engine, null, $userContext);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function page(ZoneSearch $search, int $page, string $view = 'all', string $sort = 'name', string $direction = 'ASC', string $query = 'zone', bool $comments = false): array
    {
        $parameters = ['query' => $query, 'wildcard' => true, 'reverse' => false, 'zones' => true, 'comments' => $comments];

        return $search->searchZones($parameters, $view, $sort, $direction, self::PER_PAGE, $comments, $page);
    }

    /** @param list<array<string, mixed>> $zones */
    private static function names(array $zones): array
    {
        return array_map(static fn(array $zone): string => $zone['name'], $zones);
    }

    #[DataProvider('engines')]
    public function testEveryPageHoldsAFullSetOfDistinctZones(string $engine): void
    {
        $this->createScratch($engine);
        $search = $this->search();

        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            $names = self::names($this->page($search, $page));
            $this->assertCount(self::PER_PAGE, $names, "page $page");
            $seen = array_merge($seen, $names);
        }

        $this->assertCount(self::ZONES, array_unique($seen), 'No zone repeats across pages and none is skipped');
        $this->assertSame([], $this->page($search, 4));
    }

    #[DataProvider('engines')]
    public function testEveryOwnerOfAZoneIsListedOnItsRow(string $engine): void
    {
        $this->createScratch($engine);

        $first = $this->page($this->search(), 1)[0];

        $this->assertSame('zone01.example', $first['name']);
        $this->assertSame(['Alice', 'Bob', 'Carol'], $this->sorted($first['owner_fullnames']));
    }

    #[DataProvider('engines')]
    public function testAnOwnSearchPagesOverTheUsersZonesAndShowsOnlyTheirOwnership(string $engine): void
    {
        $this->createScratch($engine);
        $search = $this->search(2);

        $first = $this->page($search, 1, 'own');
        $second = $this->page($search, 2, 'own');

        $this->assertCount(self::PER_PAGE, $first);
        $this->assertSame(['zone11.example', 'zone12.example'], self::names($second));
        $this->assertSame(['Bob'], $first[0]['owner_fullnames'], 'Co-owners the user cannot see stay hidden');
    }

    #[DataProvider('engines')]
    public function testACommentSearchFindsTheZone(string $engine): void
    {
        $this->createScratch($engine);

        $zones = $this->page($this->search(), 1, 'all', 'name', 'ASC', 'needle', true);

        $this->assertSame(['zone05.example'], self::names($zones));
    }

    /** @return array<string, array{string, string, string}> */
    public static function sorts(): array
    {
        $cases = [];
        foreach (['mysql', 'pgsql', 'sqlite'] as $engine) {
            $cases["$engine name"] = [$engine, 'name', 'zone01.example'];
            $cases["$engine type"] = [$engine, 'type', 'zone01.example'];
            $cases["$engine records"] = [$engine, 'count_records', 'zone30.example'];
            $cases["$engine owner"] = [$engine, 'fullname', 'zone01.example'];
        }
        return $cases;
    }

    #[DataProvider('sorts')]
    public function testEverySortPagesOverZones(string $engine, string $sort, string $expectedFirst): void
    {
        $this->createScratch($engine);
        $direction = $sort === 'count_records' ? 'DESC' : 'ASC';

        $zones = $this->page($this->search(), 1, 'all', $sort, $direction);

        $this->assertCount(self::PER_PAGE, $zones);
        $this->assertCount(self::PER_PAGE, array_unique(self::names($zones)));
        $this->assertSame($expectedFirst, $zones[0]['name']);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}
