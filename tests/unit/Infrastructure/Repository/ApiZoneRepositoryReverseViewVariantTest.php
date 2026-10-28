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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;

/**
 * A reverse view variant such as 2.0.192.in-addr.arpa..internal is a reverse zone in the
 * API backend's zone queries: counted with the reverse zones and kept out of the forward
 * letter list (#1786).
 */
#[CoversClass(ApiZoneRepository::class)]
class ApiZoneRepositoryReverseViewVariantTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT,
            zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, owner, zone_templ_id) VALUES
            (1, 1, '2.0.192.in-addr.arpa', 'MASTER', 1, 0),
            (2, 2, '2.0.192.in-addr.arpa..internal', 'MASTER', 1, 0),
            (3, 3, '8.b.d.0.1.0.0.2.ip6.arpa..internal', 'MASTER', 1, 0),
            (4, 4, 'example.com..internal', 'MASTER', 1, 0),
            (5, 5, 'zeta.example', 'MASTER', 1, 0)");
    }

    private function repository(): ApiZoneRepository
    {
        return new ApiZoneRepository($this->db, $this->createMock(DnsBackendProvider::class), 'sqlite', $this->createMock(ConfigurationManager::class));
    }

    public function testReverseVariantsAreCountedWithTheReverseZones(): void
    {
        $counts = $this->repository()->getReverseZoneCounts('all', 1);

        $this->assertSame(3, (int)$counts['count_all']);
        $this->assertSame(2, (int)$counts['count_ipv4']);
        $this->assertSame(1, (int)$counts['count_ipv6']);
    }

    public function testReverseVariantsStayOutOfTheForwardLetterList(): void
    {
        $letters = $this->repository()->getDistinctStartingLetters(1, true);

        $this->assertNotContains('2', $letters);
        $this->assertNotContains('8', $letters);
        $this->assertContains('e', $letters, 'A forward variant is still a forward zone');
        $this->assertContains('z', $letters);
    }
}
