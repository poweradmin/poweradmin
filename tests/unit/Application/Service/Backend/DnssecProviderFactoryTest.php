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

namespace Poweradmin\Tests\Unit\Application\Service\Backend;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Backend\DnssecProviderFactory;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Infrastructure\Service\NullDnssecProvider;
use Poweradmin\Infrastructure\Session\ArraySession;
use TestHelpers\FakeConfiguration;

/**
 * Without PowerDNS API credentials, only the SQL backend answers PRESIGNED from
 * domainmetadata; API mode must not read PowerDNS tables.
 */
#[CoversClass(DnssecProviderFactory::class)]
class DnssecProviderFactoryTest extends TestCase
{
    public function testSqlBackendWithoutTheApiReadsPresignedFromDomainmetadata(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER, kind TEXT, content TEXT)');
        $db->exec("INSERT INTO domains (id, name) VALUES (4, 'signed.example')");
        $db->exec("INSERT INTO domainmetadata (domain_id, kind, content) VALUES (4, 'PRESIGNED', '1')");

        $provider = DnssecProviderFactory::create($db, new FakeConfiguration(['dns' => ['backend' => 'sql']]), $this->createMock(ActorInterface::class), new ArraySession());

        $this->assertInstanceOf(NullDnssecProvider::class, $provider);
        $this->assertTrue($provider->isZonePresigned('signed.example'));
        $this->assertFalse($provider->isZonePresigned('example.com'));
    }

    public function testApiBackendWithoutCredentialsNeverTouchesTheDatabase(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');

        $provider = DnssecProviderFactory::create($db, new FakeConfiguration(['dns' => ['backend' => 'api']]), $this->createMock(ActorInterface::class), new ArraySession());

        $this->assertFalse($provider->isZonePresigned('signed.example'));
    }
}
