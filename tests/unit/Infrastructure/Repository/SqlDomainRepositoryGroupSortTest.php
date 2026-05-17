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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;

/**
 * Tests sort-by-group support in SqlDomainRepository::getZones (Issue #1051)
 */
#[CoversClass(SqlDomainRepository::class)]
class SqlDomainRepositoryGroupSortTest extends TestCase
{
    private PDO&MockObject $db;
    private ConfigurationManager&MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(PDO::class);
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->config->method('get')
            ->willReturnCallback(function ($group, $key, $default = null) {
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                if ($group === 'database' && $key === 'pdns_db_name') {
                    return null;
                }
                if ($group === 'dnssec' && $key === 'enabled') {
                    return false;
                }
                if ($group === 'interface' && $key === 'show_zone_comments') {
                    return false;
                }
                return $default;
            });
    }

    #[Test]
    public function getZonesSortByGroupJoinsUserGroupsAndOrdersByMinName(): void
    {
        $capturedQuery = '';
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQuery) {
                $capturedQuery = $query;
                return $stmt;
            });
        $this->db->method('query')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQuery) {
                $capturedQuery = $query;
                return $stmt;
            });
        $this->db->method('exec')->willReturn(0);

        $repository = new SqlDomainRepository($this->db, $this->config);
        $repository->getZones('all', 0, 'all', 0, 1000, 'group', 'ASC');

        $this->assertStringContainsString('LEFT JOIN zones_groups', $capturedQuery, 'Group-sort query must join zones_groups');
        $this->assertStringContainsString('LEFT JOIN user_groups', $capturedQuery, 'Group-sort query must join user_groups');
        $this->assertStringContainsString('MIN(user_groups.name) ASC', $capturedQuery, 'Query must order by aggregated user_groups.name');
        $this->assertStringContainsString('COUNT(DISTINCT', $capturedQuery, 'Group join multiplies record rows, so DISTINCT is required to keep counts accurate');
    }

    #[Test]
    public function getZonesPaginatedSortByGroupOrdersInnerSubqueryByGroupName(): void
    {
        $capturedQueries = [];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQueries) {
                $capturedQueries[] = $query;
                return $stmt;
            });
        $this->db->method('query')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQueries) {
                $capturedQueries[] = $query;
                return $stmt;
            });
        $this->db->method('exec')->willReturn(0);

        $repository = new SqlDomainRepository($this->db, $this->config);
        // letter != 'all' AND rowamount < DEFAULT_MAX_ROWS triggers the paginated limited_domains path
        $repository->getZones('all', 0, 'a', 0, 10, 'group', 'ASC');

        $combined = implode("\n", $capturedQueries);
        $this->assertStringContainsString('LEFT JOIN zones_groups', $combined, 'Inner subquery must join zones_groups for group-sorted pagination');
        $this->assertMatchesRegularExpression(
            '/GROUP BY[^O]+ORDER BY MIN\(user_groups\.name\)/s',
            $combined,
            'Inner subquery must aggregate by group name before applying LIMIT/OFFSET so pagination boundaries match the group ordering'
        );
    }

    #[Test]
    public function getZonesSortByOwnerDoesNotJoinUserGroups(): void
    {
        $capturedQuery = '';
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQuery) {
                $capturedQuery = $query;
                return $stmt;
            });
        $this->db->method('query')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQuery) {
                $capturedQuery = $query;
                return $stmt;
            });
        $this->db->method('exec')->willReturn(0);

        $repository = new SqlDomainRepository($this->db, $this->config);
        $repository->getZones('all', 0, 'all', 0, 1000, 'owner', 'ASC');

        $this->assertStringNotContainsString('LEFT JOIN user_groups', $capturedQuery, 'Non-group sort must not join user_groups');
        $this->assertStringContainsString('users.username', $capturedQuery, 'Owner sort must reference users.username');
    }
}
