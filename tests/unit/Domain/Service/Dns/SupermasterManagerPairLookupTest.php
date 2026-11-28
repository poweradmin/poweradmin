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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * An autoprimary is keyed by (ip, nameserver): two rows may share an IP.
 */
class SupermasterManagerPairLookupTest extends TestCase
{
    private SupermasterManager $manager;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE supermasters (ip TEXT, nameserver TEXT, account TEXT)');
        $db->exec('CREATE TABLE users (id INTEGER, username TEXT, fullname TEXT)');
        $db->exec("INSERT INTO supermasters VALUES ('192.0.2.1', 'ns1.example.com', 'alice'), ('192.0.2.1', 'ns2.example.com', 'bob')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed => $group === 'database' && $key === 'type' ? 'sqlite' : ($default ?? '')
        );

        $provider = $this->createMock(DnsBackendProvider::class);
        $provider->method('isApiBackend')->willReturn(false);

        $this->manager = new SupermasterManager($db, $config, $provider);
    }

    public function testLookupByPairReturnsTheMatchingRow(): void
    {
        $info = $this->manager->getSupermasterInfoFromIp('192.0.2.1', 'ns2.example.com');

        $this->assertSame('ns2.example.com', $info['ns_name']);
        $this->assertSame('bob', $info['account']);
    }

    public function testLookupOfAnUnknownPairIsEmpty(): void
    {
        $this->assertSame([], $this->manager->getSupermasterInfoFromIp('192.0.2.1', 'ns3.example.com'));
        $this->assertSame([], $this->manager->getSupermasterInfoFromIp('192.0.2.9'));
    }

    public function testLookupWithoutNameserverKeepsMatchingByIp(): void
    {
        $this->assertSame('192.0.2.1', $this->manager->getSupermasterInfoFromIp('192.0.2.1')['master_ip']);
    }
}
