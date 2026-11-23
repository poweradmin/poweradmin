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
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;

/**
 * A zone stored with mixed case (older installs, pdnsutil) must still be found
 * by its lowercase name on case-sensitive engines.
 */
class SqlDomainRepositoryCaseInsensitiveLookupTest extends TestCase
{
    private SqlDomainRepository $repo;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL, type VARCHAR(8) NOT NULL)');
        $db->exec("INSERT INTO domains (id, name, type) VALUES (7, 'Example.COM', 'NATIVE')");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn($group, $key, $default = null) => $key === 'pdns_db_name' ? null : $default);

        $this->repo = new SqlDomainRepository($db, $config);
    }

    public function testDomainExistsIgnoresCase(): void
    {
        $this->assertTrue($this->repo->domainExists('example.com'));
        $this->assertTrue($this->repo->domainExists('EXAMPLE.com'));
    }

    public function testIdLookupsIgnoreCase(): void
    {
        $this->assertSame(7, $this->repo->getDomainIdByName('example.com'));
        $this->assertSame(7, $this->repo->getZoneIdFromName('EXAMPLE.COM'));
    }

    public function testExactLowercaseRowWinsWhenBothCaseVariantsExist(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL, type VARCHAR(8) NOT NULL)');
        $db->exec("INSERT INTO domains (id, name, type) VALUES (3, 'Example.com', 'NATIVE'), (9, 'example.com', 'NATIVE')");
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn($group, $key, $default = null) => $key === 'pdns_db_name' ? null : $default);
        $repo = new SqlDomainRepository($db, $config);

        $this->assertSame(9, $repo->getZoneIdFromName('example.com'));
        $this->assertSame(9, $repo->getDomainIdByName('EXAMPLE.com'));
    }

    public function testUnknownZoneIsStillMissing(): void
    {
        $this->assertFalse($this->repo->domainExists('other.com'));
        $this->assertNull($this->repo->getZoneIdFromName('other.com'));
    }
}
