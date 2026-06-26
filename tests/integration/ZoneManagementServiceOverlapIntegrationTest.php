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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Integration test for the API zone-creation path. Confirms that the
 * acting-user argument threaded from the V1/V2 controllers into
 * ZoneManagementService::createZone() triggers the overlap guard and returns a
 * 409, against a real database with real ownership.
 *
 * Requires a running devcontainer. Skipped automatically if unavailable.
 * Run with: composer tests:integration
 */
class ZoneManagementServiceOverlapIntegrationTest extends TestCase
{
    private const DB_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=poweradmin';
    private const DB_USER = 'pdns';
    private const DB_PASS = 'poweradmin';
    private const PREFIX = 'zms-it-';

    // Seeded by import-test-data.sh: group 2 contains manager (user 2) only.
    private const MANAGER_ONLY_GROUP = 2;
    private const CLIENT = 3;

    private ?PDO $db = null;
    private array $seeded = [];

    protected function setUp(): void
    {
        try {
            $this->db = new PDO(self::DB_DSN, self::DB_USER, self::DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException) {
            $this->markTestSkipped('Devcontainer MariaDB not available.');
        }

        // Confirm the ownership fixtures are present; skip otherwise.
        $hasGroup = (int)$this->db->query(
            'SELECT COUNT(*) FROM user_group_members WHERE group_id = ' . self::MANAGER_ONLY_GROUP
            . ' AND user_id = ' . self::CLIENT
        )->fetchColumn();
        if ($hasGroup !== 0) {
            $this->markTestSkipped('Expected test-data group membership not present.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->seeded as $id) {
            $this->db->prepare('DELETE FROM zones_groups WHERE domain_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM pdns.domains WHERE id = ?')->execute([$id]);
        }
    }

    private function seedGroupOwnedZone(string $name): void
    {
        $this->db->prepare("INSERT INTO pdns.domains (name, type) VALUES (?, 'NATIVE')")->execute([$name]);
        $id = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO zones_groups (domain_id, group_id, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)')
            ->execute([$id, self::MANAGER_ONLY_GROUP]);
        $this->seeded[] = $id;
    }

    private function makeService(): ZoneManagementService
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            function (string $group, string $key, $default = null) {
                if ($group === 'dns' && $key === 'parent_zone_ownership_check') {
                    return true;
                }
                if ($group === 'database' && $key === 'pdns_db_name') {
                    return 'pdns';
                }
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return $default;
            }
        );

        return new ZoneManagementService($this->createMock(ZoneRepositoryInterface::class), $config, $this->db);
    }

    public function testApiCreateRejectsOverlapForNonOwnerWith409(): void
    {
        $parent = self::PREFIX . 'parent.example.test';
        $this->seedGroupOwnedZone($parent);

        // client is not in the owning group, so the child overlaps another owner's zone.
        $result = $this->makeService()->createZone(
            'child.' . $parent,
            'MASTER',
            self::CLIENT,
            '',
            'none',
            false,
            [],
            self::CLIENT
        );

        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status']);
        $this->assertStringContainsString('overlaps', $result['message']);
    }
}
