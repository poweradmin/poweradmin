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
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * The API v2 ?name= filter matches a zone name regardless of the case typed, and a
 * lowercase twin outside the caller's zones does not hide the caller's zone.
 */
class DbZoneRepositoryNameFilterCaseTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'alpha.example', 'MASTER'), (2, 'Beta.example', 'SLAVE')");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (1, 5), (2, 5)");
    }

    private function repository(): DbZoneRepository
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => ($group === 'database' && $key === 'type') ? 'sqlite' : $default
        );

        return new DbZoneRepository($this->db, $config);
    }

    /** @return string[] */
    private function names(array $zones): array
    {
        return array_column($zones, 'name');
    }

    public function testTheNameFilterMatchesAMixedCaseRowRegardlessOfTheCaseTyped(): void
    {
        $repository = $this->repository();

        $this->assertSame(['Beta.example'], $this->names($repository->getAllZonesFiltered(null, null, 'beta.example')));
        $this->assertSame(['Beta.example'], $this->names($repository->getAllZonesFiltered(null, null, 'BETA.EXAMPLE')));
        $this->assertSame(1, $repository->getZoneCountFiltered(null, null, 'Beta.Example'));
        $this->assertSame(['alpha.example'], $this->names($repository->getAllZonesFiltered(null, null, 'ALPHA.example')));
    }

    public function testALowercaseTwinOutsideTheAllowlistDoesNotHideTheCallersZone(): void
    {
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (6, 'beta.example', 'MASTER')");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (6, 9)");
        $repository = $this->repository();

        $this->assertSame(['Beta.example'], $this->names($repository->getAllZonesFiltered([2], 5, 'Beta.example')));
        $this->assertSame(1, $repository->getZoneCountFiltered([2], 5, 'Beta.example'));
    }
}
