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
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * Tests for group ownership support in DbZoneRepository (Issue #1042)
 *
 * Verifies that zone queries include group membership checks via zones_groups,
 * so zones with only group ownership (no direct user owner) are visible to
 * group members.
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryGroupOwnershipTest extends TestCase
{
    private PDOCommon&MockObject $db;
    private ConfigurationManager&MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(PDOCommon::class);
        $this->config = $this->createMock(ConfigurationManager::class);
    }

    private function setupConfig(): void
    {
        $this->config->method('get')
            ->willReturnCallback(function ($group, $key, $default = null) {
                if ($group === 'database' && $key === 'pdns_db_name') {
                    return null;
                }
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return $default;
            });
    }

    #[Test]
    public function getDistinctStartingLettersIncludesGroupOwnershipCheck(): void
    {
        $this->setupConfig();

        $capturedQuery = '';
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn(['a', 'b', 'c']);
        $stmt->method('bindValue')->willReturn(true);

        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQuery) {
                $capturedQuery = $query;
                return $stmt;
            });

        $repository = new DbZoneRepository($this->db, $this->config);
        $repository->getDistinctStartingLetters(5, false);

        $this->assertStringContainsString('zones_groups', $capturedQuery, 'Query must check zones_groups for group ownership');
        $this->assertStringContainsString('user_group_members', $capturedQuery, 'Query must check user_group_members for group membership');
    }

    #[Test]
    public function getDistinctStartingLettersSkipsGroupCheckForViewOthers(): void
    {
        $this->setupConfig();

        $capturedQuery = '';
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn(['a', 'b', 'c']);

        $this->db->method('query')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQuery) {
                $capturedQuery = $query;
                return $stmt;
            });

        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQuery) {
                $capturedQuery = $query;
                return $stmt;
            });

        $repository = new DbZoneRepository($this->db, $this->config);
        $repository->getDistinctStartingLetters(5, true);

        $this->assertStringNotContainsString('zones_groups', $capturedQuery, 'Query should not check groups when viewOthers is true');
    }

    #[Test]
    public function getReverseZonesIncludesGroupOwnershipCheck(): void
    {
        $this->setupConfig();

        $capturedQuery = '';
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('bindValue')->willReturn(true);

        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQuery) {
                $capturedQuery = $query;
                return $stmt;
            });

        $repository = new DbZoneRepository($this->db, $this->config);
        $repository->getReverseZones('own', 5);

        $this->assertStringContainsString('zones_groups', $capturedQuery, 'Query must check zones_groups for group ownership');
        $this->assertStringContainsString('user_group_members', $capturedQuery, 'Query must check user_group_members for group membership');
    }

    #[Test]
    public function getReverseZonesSkipsGroupCheckForAllPermType(): void
    {
        $this->setupConfig();

        $capturedQuery = '';
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('bindValue')->willReturn(true);

        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQuery) {
                $capturedQuery = $query;
                return $stmt;
            });

        $repository = new DbZoneRepository($this->db, $this->config);
        $repository->getReverseZones('all', 5);

        $this->assertStringNotContainsString('zones_groups', $capturedQuery, 'Query should not check groups for "all" permType');
    }

    #[Test]
    public function getReverseZoneCountOnlyIncludesGroupOwnershipCheck(): void
    {
        $this->setupConfig();

        $capturedQuery = '';
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(3);
        $stmt->method('bindValue')->willReturn(true);

        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQuery) {
                $capturedQuery = $query;
                return $stmt;
            });

        $repository = new DbZoneRepository($this->db, $this->config);
        $repository->getReverseZones('own', 5, 'all', 0, 25, 'name', 'ASC', true);

        $this->assertStringContainsString('zones_groups', $capturedQuery, 'Count query must check zones_groups for group ownership');
        $this->assertStringContainsString('user_group_members', $capturedQuery, 'Count query must check user_group_members for group membership');
    }

    #[Test]
    public function getReverseZoneCountsIncludesGroupOwnershipCheck(): void
    {
        $this->setupConfig();

        $capturedQuery = '';
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['count_all' => 2, 'count_ipv4' => 1, 'count_ipv6' => 1]);
        $stmt->method('bindValue')->willReturn(true);

        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use ($stmt, &$capturedQuery) {
                $capturedQuery = $query;
                return $stmt;
            });

        $repository = new DbZoneRepository($this->db, $this->config);
        $repository->getReverseZoneCounts('own', 5);

        $this->assertStringContainsString('zones_groups', $capturedQuery, 'Count query must check zones_groups for group ownership');
        $this->assertStringContainsString('user_group_members', $capturedQuery, 'Count query must check user_group_members for group membership');
    }
}
