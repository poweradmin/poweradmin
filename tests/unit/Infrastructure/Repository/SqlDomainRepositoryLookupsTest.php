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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;

/**
 * Pins the four id lookups on the SQL domain repository. The master lookup must
 * answer for every zone kind: a CONSUMER zone replicates from a primary just like
 * a SLAVE zone, and the edit page shows that primary for both.
 */
#[CoversClass(SqlDomainRepository::class)]
class SqlDomainRepositoryLookupsTest extends TestCase
{
    private SqlDomainRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, master TEXT, type TEXT)");
        $db->exec("INSERT INTO domains (id, name, master, type) VALUES
            (1, 'native.example.com', NULL, 'NATIVE'),
            (2, 'slave.example.com', '192.0.2.1', 'SLAVE'),
            (3, 'consumer.example.com', '192.0.2.2', 'CONSUMER'),
            (4, 'empty-master.example.com', '', 'MASTER'),
            (5, 'untyped.example.com', NULL, '')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );

        $this->repository = new SqlDomainRepository($db, $config);
    }

    #[Test]
    public function zoneIdExistsAnswersFromTheDomainsTable(): void
    {
        $this->assertTrue($this->repository->zoneIdExists(1));
        $this->assertTrue($this->repository->zoneIdExists(3));
        $this->assertFalse($this->repository->zoneIdExists(99));
    }

    #[Test]
    public function getDomainNameByIdReturnsTheNameOrNull(): void
    {
        $this->assertSame('slave.example.com', $this->repository->getDomainNameById(2));
        $this->assertNull($this->repository->getDomainNameById(99));
    }

    #[Test]
    public function getDomainTypeReturnsTheStoredKind(): void
    {
        $this->assertSame('NATIVE', $this->repository->getDomainType(1));
        $this->assertSame('SLAVE', $this->repository->getDomainType(2));
        $this->assertSame('CONSUMER', $this->repository->getDomainType(3));
    }

    #[Test]
    public function getDomainTypeDefaultsToNativeWhenUnknownOrEmpty(): void
    {
        $this->assertSame('NATIVE', $this->repository->getDomainType(5));
        $this->assertSame('NATIVE', $this->repository->getDomainType(99));
    }

    #[Test]
    public function getDomainSlaveMasterReturnsTheMasterForSlaveZones(): void
    {
        $this->assertSame('192.0.2.1', $this->repository->getDomainSlaveMaster(2));
    }

    #[Test]
    public function getDomainSlaveMasterReturnsTheMasterForConsumerZones(): void
    {
        $this->assertSame('192.0.2.2', $this->repository->getDomainSlaveMaster(3));
    }

    #[Test]
    public function getDomainSlaveMasterReturnsNullWhenNoMasterIsStored(): void
    {
        $this->assertNull($this->repository->getDomainSlaveMaster(1));
        $this->assertNull($this->repository->getDomainSlaveMaster(4));
        $this->assertNull($this->repository->getDomainSlaveMaster(99));
    }
}
