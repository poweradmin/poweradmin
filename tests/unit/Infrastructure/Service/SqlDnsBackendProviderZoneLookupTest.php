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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;
use TestHelpers\SqliteDnsBackendTestCase;

#[CoversClass(SqlDnsBackendProvider::class)]
class SqlDnsBackendProviderZoneLookupTest extends SqliteDnsBackendTestCase
{
    public function testZoneLookupFindsAMixedCaseRowRegardlessOfTheCaseTyped(): void
    {
        $db = $this->sqliteRecordsDb();
        $db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'Mixed.Example', 'NATIVE'), (2, 'plain.example', 'NATIVE')");
        $provider = new SqlDnsBackendProvider($db, new \TestHelpers\FakeConfiguration(), new \Psr\Log\NullLogger());

        $this->assertTrue($provider->zoneExists('mixed.example'));
        $this->assertTrue($provider->zoneExists('MIXED.example'));
        $this->assertSame(1, $provider->getZoneIdByName('mixed.EXAMPLE'));
        $this->assertSame(2, $provider->getZoneIdByName('PLAIN.example'));
        $this->assertFalse($provider->zoneExists('absent.example'));
        $this->assertNull($provider->getZoneIdByName('absent.example'));
    }

    public function testAnExactLowercaseRowWinsOverAnEarlierMixedCaseOne(): void
    {
        $db = $this->sqliteRecordsDb();
        $db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'Dup.Example', 'NATIVE'), (2, 'dup.example', 'NATIVE')");
        $provider = new SqlDnsBackendProvider($db, new \TestHelpers\FakeConfiguration(), new \Psr\Log\NullLogger());

        $this->assertSame(2, $provider->getZoneIdByName('Dup.Example'));
    }
}
