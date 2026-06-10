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

namespace Poweradmin\Tests\Unit\Domain\Repository;

use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\DomainRepository;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Regression tests for group ownership in the forward zone listing (Issue #1329).
 *
 * A forward zone owned only by a group (no direct user owner) must be visible
 * to group members. The forward fetch query (DomainRepository::getZones) must
 * therefore include the same group-membership check the count uses, otherwise
 * the zone is counted but missing from the table.
 */
#[CoversClass(DomainRepository::class)]
class DomainRepositoryGroupOwnershipTest extends TestCase
{
    private PDOCommon&MockObject $db;
    private ConfigurationManager&MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(PDOCommon::class);
        $this->config = $this->createMock(ConfigurationManager::class);
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

    /**
     * Capture every SQL string DomainRepository::getZones prepares so the
     * assertions can inspect the owner-filter clause regardless of which
     * internal query path runs.
     */
    private function captureGetZonesQueries(string $perm): string
    {
        $captured = '';
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('fetch')->willReturn([]);
        $stmt->method('fetchColumn')->willReturn('');
        $stmt->method('bindValue')->willReturn(true);

        $capture = function ($query) use ($stmt, &$captured) {
            $captured .= "\n" . $query;
            return $stmt;
        };
        $this->db->method('prepare')->willReturnCallback($capture);
        $this->db->method('query')->willReturnCallback($capture);
        $this->db->method('exec')->willReturn(0);

        $repository = new DomainRepository($this->db, $this->config);
        $repository->getZones($perm, 5, 'all', 0, 25, 'name', 'ASC', true);

        return $captured;
    }

    #[Test]
    public function getZonesIncludesGroupOwnershipCheckForOwnPermType(): void
    {
        $captured = $this->captureGetZonesQueries('own');

        $this->assertStringContainsString('zones_groups', $captured, 'Forward zone fetch must check zones_groups for group ownership');
        $this->assertStringContainsString('user_group_members', $captured, 'Forward zone fetch must check user_group_members for group membership');
    }

    #[Test]
    public function getZonesSkipsGroupCheckForAllPermType(): void
    {
        $captured = $this->captureGetZonesQueries('all');

        $this->assertStringNotContainsString('zones_groups', $captured, 'Forward zone fetch should not filter by group for "all" permType');
    }
}
