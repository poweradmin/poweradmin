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
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;

/**
 * The reverse zone list offers Type as a sortable column, so the API-mode
 * repository must actually order by it rather than silently falling through
 * to the zone name.
 */
#[CoversClass(ApiZoneRepository::class)]
class ApiZoneRepositoryReverseSortTest extends TestCase
{
    private PDO $db;
    private DnsBackendProvider $backend;

    protected function setUp(): void
    {
        // Hold the sync throttle closed: this test drives the listing only, and
        // a sync against a stubbed provider would see zero API zones and delete
        // every local row as orphaned
        $_SESSION['zone_sync_last'] = time();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT,
            zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (1, 'admin', 'Administrator')");
        // Names ascend while types descend, so ordering by one is visibly
        // different from ordering by the other
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id) VALUES
            (1, 11, '1.168.192.in-addr.arpa', 'SLAVE',  '', '', 1, 0),
            (2, 12, '2.168.192.in-addr.arpa', 'NATIVE', '', '', 1, 0),
            (3, 13, '3.168.192.in-addr.arpa', 'MASTER', '', '', 1, 0)");

        $this->backend = $this->createMock(DnsBackendProvider::class);
        $this->backend->method('getZoneStats')->willReturn([]);
        $this->backend->method('countZoneRecords')->willReturn(0);
        $this->backend->method('getZoneSoaHealth')->willReturn(['is_disabled' => false, 'is_missing_soa' => false]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['zone_sync_last']);
    }

    private function repository(): ApiZoneRepository
    {
        return new ApiZoneRepository($this->db, $this->backend, 'sqlite', $this->createMock(ConfigurationManager::class));
    }

    /** @return array<int, array<string, mixed>> */
    private function reverseZones(string $sortBy, string $direction): array
    {
        return array_values($this->repository()->getReverseZones('all', 1, 'all', 0, 25, $sortBy, $direction));
    }

    /** @return string[] */
    private function typesInOrder(string $sortBy, string $direction): array
    {
        return array_map(static fn(array $zone): string => $zone['type'], $this->reverseZones($sortBy, $direction));
    }

    /** @return string[] */
    private function namesInOrder(string $sortBy, string $direction): array
    {
        return array_map(static fn(array $zone): string => $zone['name'], $this->reverseZones($sortBy, $direction));
    }

    #[Test]
    public function sortingByTypeOrdersByZoneTypeNotZoneName(): void
    {
        $this->assertSame(['MASTER', 'NATIVE', 'SLAVE'], $this->typesInOrder('type', 'ASC'));
    }

    #[Test]
    public function sortingByTypeHonoursTheDirection(): void
    {
        $this->assertSame(['SLAVE', 'NATIVE', 'MASTER'], $this->typesInOrder('type', 'DESC'));
    }

    #[Test]
    public function sortingByNameStillOrdersByZoneName(): void
    {
        $this->assertSame(
            ['1.168.192.in-addr.arpa', '2.168.192.in-addr.arpa', '3.168.192.in-addr.arpa'],
            $this->namesInOrder('name', 'ASC')
        );
    }

    #[Test]
    public function anUnsupportedSortDirectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository()->getReverseZones('all', 1, 'all', 0, 25, 'name', 'ASC; DROP TABLE zones');
    }
}
