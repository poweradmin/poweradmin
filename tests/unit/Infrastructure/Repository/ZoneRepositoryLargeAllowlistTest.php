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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * GET /api/v2/zones passes the visible or key-scoped zone ids to the repository. A
 * large list must still restrict the result without one bind parameter per id.
 */
class ZoneRepositoryLargeAllowlistTest extends TestCase
{
    private PDO $db;
    /** @var string[] */
    private array $preparedSql = [];

    protected function setUp(): void
    {
        $_SESSION['zone_sync_last'] = time();
        $this->preparedSql = [];
        $sink = &$this->preparedSql;
        $this->db = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO {
            public array $sink = [];

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->sink[] = $query;
                return parent::prepare($query, $options);
            }
        };
        $this->db->sink = &$sink;
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT,
            zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'alpha.example', 'MASTER'), (2, 'beta.example', 'MASTER'), (3, 'gamma.example', 'MASTER')");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, owner) VALUES
            (1, 1, 'alpha.example', 'MASTER', 5), (2, 2, 'beta.example', 'MASTER', 5), (3, 3, 'gamma.example', 'MASTER', 9)");
    }

    private function dbRepository(): DbZoneRepository
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => ($group === 'database' && $key === 'type') ? 'sqlite' : $default
        );

        return new DbZoneRepository($this->db, $config);
    }

    private function apiRepository(): ApiZoneRepository
    {
        return new ApiZoneRepository($this->db, $this->createMock(DnsBackendProvider::class), 'sqlite', $this->createMock(ConfigurationManager::class));
    }

    private function assertNoPerIdPlaceholders(): void
    {
        $this->assertSame([], array_values(array_filter($this->preparedSql, fn(string $sql) => str_contains($sql, ':zone_id_'))));
    }

    public function testTheSqlRepositoryRestrictsToALargeKeyScopeWithoutBindingEachId(): void
    {
        $repository = $this->dbRepository();
        $ids = array_merge(range(100000, 140000), [2, 3]);

        $this->assertSame(['beta.example', 'gamma.example'], array_column($repository->getAllZonesFiltered($ids, null), 'name'));
        $this->assertSame(2, $repository->getZoneCountFiltered($ids, null));
        $this->assertSame(['gamma.example'], array_column($repository->getAllZonesFiltered($ids, null, null, 1, 1), 'name'));
        $this->assertSame(['beta.example'], array_column($repository->getAllZonesFiltered($ids, 5), 'name'));
        $this->assertSame(1, $repository->getZoneCountFiltered($ids, 5));
        $this->assertNoPerIdPlaceholders();
    }

    public function testTheApiRepositoryRestrictsToALargeKeyScopeWithoutBindingEachId(): void
    {
        $repository = $this->apiRepository();
        $ids = array_merge(range(100000, 140000), [2, 3]);

        $this->assertSame(['beta.example', 'gamma.example'], array_column($repository->getAllZonesFiltered($ids, null), 'name'));
        $this->assertSame(2, $repository->getZoneCountFiltered($ids, null));
        $this->assertSame(['gamma.example'], array_column($repository->getAllZonesFiltered($ids, null, null, 1, 1), 'name'));
        $this->assertSame(['beta.example'], array_column($repository->getAllZonesFiltered($ids, 5), 'name'));
        $this->assertNoPerIdPlaceholders();
    }
}
