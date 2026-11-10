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

namespace Unit\Domain\Service;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * PostgreSQL and SQLite compare zone names case-sensitively, so a name that keeps its
 * case would slip past the duplicate check against the stored lowercase name.
 */
class ZoneManagementServiceNameCaseTest extends SqliteIntegrationTestCase
{
    #[RunInSeparateProcess]
    public function testAMixedCaseNameIsCheckedAgainstTheStoredLowercaseName(): void
    {
        $this->db->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT, account TEXT)');
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'ab--cd.com', 'MASTER')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(static fn(string $group, string $key, mixed $default = null): mixed => match ([$group, $key]) {
            ['database', 'type'] => 'sqlite',
            ['database', 'pdns_db_name'] => '',
            default => $default,
        });
        $service = new ZoneManagementService($this->createMock(ZoneRepositoryInterface::class), $config, $this->db);

        $result = $service->createZone('AB--CD.COM', 'MASTER', 1);

        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status']);
    }
}
