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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * A name under another user's zone gets the overlap refusal, not the existing-records
 * one, so the create form does not reveal which names hold records in other zones.
 */
#[CoversClass(ZoneManagementService::class)]
class ZoneManagementServiceCheckOrderTest extends SqliteIntegrationTestCase
{
    private const OTHER_USER = 2;
    private const OVERLAP = 'Cannot create this zone because it overlaps an existing zone owned by another user.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, master TEXT, account TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, owner INTEGER, zone_name TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (2, 'Client')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::OTHER_USER . ", 'client', 2)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'parent.example', 'MASTER')");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, 1, " . self::ADMIN_USER_ID . ")");
        $this->db->exec("INSERT INTO records (domain_id, name, type, content) VALUES
            (1, 'www.parent.example', 'A', '192.0.2.1'),
            (NULL, 'orphan.example', 'A', '192.0.2.2')");
    }

    private function service(): ZoneManagementService
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(static fn(string $group, string $key, mixed $default = null): mixed => match ([$group, $key]) {
            ['database', 'type'] => 'sqlite',
            ['database', 'pdns_db_name'] => '',
            default => $default,
        });

        return new ZoneManagementService($this->createMock(ZoneRepositoryInterface::class), $config, $this->db);
    }

    #[RunInSeparateProcess]
    public function testNameWithRecordsUnderAnotherUsersZoneGetsTheOverlapRefusal(): void
    {
        $result = $this->service()->createZone('www.parent.example', 'MASTER', self::OTHER_USER, '', 'none', false, self::OTHER_USER);

        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status']);
        $this->assertSame(self::OVERLAP, $result['message']);
    }

    #[RunInSeparateProcess]
    public function testNameWithRecordsOutsideAnyForeignZoneStillGetsTheExistingRefusal(): void
    {
        $result = $this->service()->createZone('orphan.example', 'MASTER', self::OTHER_USER, '', 'none', false, self::OTHER_USER);

        $this->assertSame('Domain already exists', $result['message']);
    }
}
