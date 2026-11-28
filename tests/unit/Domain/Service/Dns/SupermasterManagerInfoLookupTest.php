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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

#[CoversClass(SupermasterManager::class)]
class SupermasterManagerInfoLookupTest extends TestCase
{
    private function manager(array &$captured, array $row): SupermasterManager
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function ($params = null) use (&$captured) {
            $captured['params'] = $params;
            return true;
        });
        $stmt->method('fetch')->willReturn($row);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function ($sql) use (&$captured, $stmt) {
            $captured['sql'] = $sql;
            return $stmt;
        });

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnMap([
            ['database', 'pdns_db_name', null, ''],
            ['database', 'type', null, 'mysql'],
            ['idn', 'idn_enabled', null, false],
        ]);

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(false);

        return new SupermasterManager($db, $config, $backend);
    }

    public function testLookupByPairFiltersOnNameserver(): void
    {
        $captured = [];
        $row = ['ip' => '192.0.2.1', 'nameserver' => 'ns2.example.com', 'account' => 'bob', 'fullname' => null];
        $info = $this->manager($captured, $row)->getSupermasterInfoFromIp('192.0.2.1', 'ns2.example.com');

        $this->assertStringContainsString('s.nameserver = :ns_name', $captured['sql']);
        $this->assertSame([':master_ip' => '192.0.2.1', ':ns_name' => 'ns2.example.com'], $captured['params']);
        $this->assertSame('ns2.example.com', $info['ns_name']);
    }

    public function testLookupByIpOnlyKeepsOldQuery(): void
    {
        $captured = [];
        $row = ['ip' => '192.0.2.1', 'nameserver' => 'ns1.example.com', 'account' => 'bob', 'fullname' => null];
        $this->manager($captured, $row)->getSupermasterInfoFromIp('192.0.2.1');

        $this->assertStringNotContainsString('nameserver = :ns_name', $captured['sql']);
        $this->assertSame([':master_ip' => '192.0.2.1'], $captured['params']);
    }
}
