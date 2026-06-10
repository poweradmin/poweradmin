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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneCountService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Regression tests for group ownership in the forward zone count (Issue #1329).
 *
 * The forward zone count must include the same group-membership check as the
 * forward fetch. If the count counted group-owned zones but the fetch did not
 * return them, the list would show a non-zero total over an empty table.
 */
#[CoversClass(ZoneCountService::class)]
class ZoneCountServiceGroupOwnershipTest extends TestCase
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
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return $default;
            });
    }

    private function captureCountQuery(string $perm, ?int $userId): string
    {
        $captured = '';
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['count_zones' => 0]);
        $stmt->method('fetchColumn')->willReturn('');
        $stmt->method('bindValue')->willReturn(true);

        $capture = function ($query) use ($stmt, &$captured) {
            $captured .= "\n" . $query;
            return $stmt;
        };
        $this->db->method('prepare')->willReturnCallback($capture);
        $this->db->method('query')->willReturnCallback($capture);
        $this->db->method('exec')->willReturn(0);

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn($userId);

        $service = new ZoneCountService($this->db, $this->config, $userContext);
        $service->countZones($perm, 'all', 'forward');

        return $captured;
    }

    #[Test]
    public function countZonesIncludesGroupOwnershipCheckForOwnPermType(): void
    {
        $captured = $this->captureCountQuery('own', 5);

        $this->assertStringContainsString('zones_groups', $captured, 'Forward zone count must check zones_groups for group ownership');
        $this->assertStringContainsString('user_group_members', $captured, 'Forward zone count must check user_group_members for group membership');
    }

    #[Test]
    public function countZonesSkipsGroupCheckForAllPermType(): void
    {
        $captured = $this->captureCountQuery('all', 5);

        $this->assertStringNotContainsString('zones_groups', $captured, 'Forward zone count should not filter by group for "all" permType');
    }
}
