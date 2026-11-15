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
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Repository\SqlDynamicDnsRepository;

/**
 * getUserZones() hands the service each owned zone's name, so a hostname can be matched
 * against the zone it belongs to instead of being written into every owned zone.
 */
class SqlDynamicDnsRepositoryUserZonesTest extends TestCase
{
    public function testOwnedZonesAreReturnedAsIdToName(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)");
        $db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("INSERT INTO domains (id, name) VALUES (1, 'mine.example'), (2, 'sub.mine.example'), (3, 'theirs.example')");
        $db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, 1, 5), (2, 2, 5), (3, 3, 6), (4, 2, 5)");

        $repository = new SqlDynamicDnsRepository($db, $this->createMock(DnsRecord::class), 'records');
        $zones = $repository->getUserZones(new User(5, 'hash', false));
        ksort($zones);

        $this->assertSame([1 => 'mine.example', 2 => 'sub.mine.example'], $zones);
    }
}
