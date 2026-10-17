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
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneCountService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Configuration\FakeConfiguration;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;

/**
 * The forward zone list's letter filter lists punycode zones under one 'idn' entry and
 * keeps them out of 'x'. The letter list, the zone count and the zone page come from
 * different queries, so they must agree or the pager is wrong (#1735).
 *
 * Runs against a per-process scratch database on each engine; MySQL and PostgreSQL need
 * the devcontainer and are skipped without it.
 */
class ZoneStartingLetterIntegrationTest extends TestCase
{
    private static string $scratch = '';
    private PDO $db;
    private string $engine;

    protected function setUp(): void
    {
        self::$scratch = 'poweradmin_it_letter_' . getmypid();
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
     * Two punycode zones, one ASCII zone under x, one zone with only a punycode TLD, one
     * zone under a. User 1 owns all of them, user 2 only the a zone.
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

        $this->db->exec('CREATE TABLE domains (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL, type VARCHAR(8) NOT NULL, master VARCHAR(128) NULL, account VARCHAR(40) NULL)');
        $disabled = $engine === 'pgsql' ? 'BOOL NOT NULL DEFAULT false' : 'INT NOT NULL DEFAULT 0';
        $this->db->exec("CREATE TABLE records (id INT PRIMARY KEY, domain_id INT NOT NULL, name VARCHAR(255) NULL, type VARCHAR(10) NULL, content VARCHAR(255) NULL, disabled $disabled)");
        $this->db->exec('CREATE TABLE zones (id INT PRIMARY KEY, domain_id INT NOT NULL, owner INT NULL, comment VARCHAR(1024) NULL, zone_templ_id INT NOT NULL DEFAULT 0)');
        $this->db->exec('CREATE TABLE zone_templ (id INT PRIMARY KEY, name VARCHAR(128) NOT NULL)');
        $this->db->exec('CREATE TABLE users (id INT PRIMARY KEY, username VARCHAR(64) NOT NULL, fullname VARCHAR(255) NULL)');
        $this->db->exec('CREATE TABLE user_groups (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL)');
        $this->db->exec('CREATE TABLE zones_groups (id INT PRIMARY KEY, domain_id INT NOT NULL, group_id INT NOT NULL)');
        $this->db->exec('CREATE TABLE user_group_members (id INT PRIMARY KEY, user_id INT NOT NULL, group_id INT NOT NULL)');

        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (1, 'admin', 'Alice'), (2, 'client', 'Bob')");
        $names = ['alpha.example', 'xray.example', 'xn--mnchen-3ya.example', 'xn--e1afmkfd.example', 'example.xn--p1ai'];
        foreach ($names as $index => $name) {
            $id = $index + 1;
            $this->db->exec("INSERT INTO domains (id, name, type) VALUES ($id, '$name', 'NATIVE')");
            $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES ($id, $id, 1)");
        }
        $this->db->exec('INSERT INTO zones (id, domain_id, owner) VALUES (100, 1, 2)');
    }

    private function config(): FakeConfiguration
    {
        return new FakeConfiguration(['database' => ['type' => $this->engine]]);
    }

    private function counted(string $perm, string $letter): int
    {
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(1);

        return (new ZoneCountService($this->db, $this->config(), $userContext))->countZones($perm, $letter);
    }

    /** @return list<string> */
    private function listed(string $letter): array
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, ?string $key = null, mixed $default = null): mixed => $group === 'database' && $key === 'type' ? $this->engine : $default);
        $zones = (new SqlDomainRepository($this->db, $config))->getZones('all', 1, $letter, 0, 50);
        $names = array_map(static fn(array $zone): string => $zone['name'], array_values($zones));
        sort($names);
        return array_values($names);
    }

    #[DataProvider('engines')]
    public function testPunycodeZonesHaveOneIdnEntryAndStayOutOfX(string $engine): void
    {
        $this->createScratch($engine);
        $repository = new DbZoneRepository($this->db, $this->config());

        $this->assertSame(['a', 'e', 'x', 'idn'], $repository->getDistinctStartingLetters(1, true));
        $this->assertSame(['a'], $repository->getDistinctStartingLetters(2, false), 'No IDN entry without a visible punycode zone');
    }

    #[DataProvider('engines')]
    public function testCountAndListAgreeForEachBucket(string $engine): void
    {
        $this->createScratch($engine);

        $expected = [
            'idn' => ['xn--e1afmkfd.example', 'xn--mnchen-3ya.example'],
            'x' => ['xray.example'],
            'e' => ['example.xn--p1ai'],
            'a' => ['alpha.example'],
        ];
        foreach ($expected as $letter => $names) {
            $this->assertSame($names, $this->listed($letter), "list for $letter");
            $this->assertSame(count($names), $this->counted('all', $letter), "count for $letter");
            $this->assertSame(count($names), $this->counted('own', $letter), "own count for $letter");
        }
    }
}
