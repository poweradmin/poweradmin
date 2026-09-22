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
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Repository\ApiDomainRepository;
use TestHelpers\FakeConfiguration;

/**
 * The exact rows the forward zone list receives from the API backend, keys in
 * order and values typed as the repository assembles them.
 */
#[CoversClass(ApiDomainRepository::class)]
class ApiDomainRepositoryZoneListShapeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Hold the sync throttle closed: a sync against the stubbed provider would
        // reconcile the local rows against the mock's zone list
        $_SESSION['zone_sync_last'] = time();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT NULL, zone_type TEXT,
                    zone_master TEXT, comment TEXT, owner INTEGER NULL, zone_templ_id INTEGER,
                    is_disabled INTEGER NOT NULL DEFAULT 0, is_missing_soa INTEGER NOT NULL DEFAULT 0, last_synced_at INTEGER)",
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)",
                "CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)",
                "CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)",
                "INSERT INTO users VALUES (5, 'alice', 'Alice A'), (6, 'bob', NULL)",
                "INSERT INTO zone_templ VALUES (1, 'Basic')",
                "INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id) VALUES
                    (7, 0, 'signed.example', 'MASTER', '', 'signed zone', 5, 1),
                    (8, 7, NULL, NULL, NULL, NULL, 6, 0),
                    (9, 0, 'plain.example', 'NATIVE', '', '', NULL, 0),
                    (10, 0, '2.0.192.in-addr.arpa', 'MASTER', '', 'reverse', 5, 0)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }

    protected function tearDown(): void
    {
        unset($_SESSION['zone_sync_last']);
    }

    private function backend(): DnsBackendProviderInterface
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('getZones')->willReturn([
            ['id' => 7, 'name' => 'signed.example', 'type' => 'MASTER', 'master' => '', 'dnssec' => true],
            ['id' => 9, 'name' => 'plain.example', 'type' => 'NATIVE', 'master' => '', 'dnssec' => false],
            ['id' => 10, 'name' => '2.0.192.in-addr.arpa', 'type' => 'MASTER', 'master' => '', 'dnssec' => false],
        ]);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('allocatesZoneIdsLocally')->willReturn(true);
        $backend->method('countZoneRecords')->willReturnCallback(fn(int $id) => $id === 7 ? 3 : 1);
        // A failed health lookup on plain.example must read as unknown, not healthy
        $backend->method('getZoneSoaHealth')->willReturnCallback(
            fn(string $name) => $name === 'plain.example' ? null : ['is_disabled' => false, 'is_missing_soa' => false]
        );
        $backend->method('getZoneStats')->willReturn([
            'signed.example.' => ['dnssec' => true, 'serial' => 2024010101, 'edited_serial' => 2024010105, 'notified_serial' => 2024010100],
            'plain.example.' => ['dnssec' => false, 'serial' => 5, 'edited_serial' => null, 'notified_serial' => null],
            '2.0.192.in-addr.arpa.' => ['dnssec' => false, 'serial' => 7, 'edited_serial' => null, 'notified_serial' => 7],
        ]);

        return $backend;
    }

    private function repository(bool $signedSerial): ApiDomainRepository
    {
        return new ApiDomainRepository($this->db, new FakeConfiguration([
            'database' => ['type' => 'sqlite'],
            'dnssec' => ['enabled' => true],
            'interface' => ['display_signed_serial_in_zone_list' => $signedSerial],
        ]), $this->backend());
    }

    public function testForwardListWithEveryColumnEnabled(): void
    {
        $rows = $this->repository(true)->getZones('all', 0, 'all', 0, 9999, 'name', 'ASC', true, true, true);

        $this->assertSame([
            'plain.example' => [
                'id' => 9,
                'name' => 'plain.example',
                'utf8_name' => 'plain.example',
                'type' => 'NATIVE',
                'count_records' => 1,
                'is_disabled' => false,
                'is_missing_soa' => false,
                'soa_health' => 'unknown',
                'comment' => '',
                'secured' => false,
                'owners' => [],
                'full_names' => [],
                'users' => [],
                'serial' => '5',
                'signed_serial' => '',
                'template' => '',
            ],
            'signed.example' => [
                'id' => 7,
                'name' => 'signed.example',
                'utf8_name' => 'signed.example',
                'type' => 'MASTER',
                'count_records' => 3,
                'is_disabled' => false,
                'is_missing_soa' => false,
                'soa_health' => 'ok',
                'comment' => 'signed zone',
                'secured' => true,
                'owners' => ['alice', 'bob'],
                'full_names' => ['Alice A', ''],
                'users' => ['alice', 'bob'],
                'serial' => '2024010101',
                'signed_serial' => '2024010105',
                'template' => 'Basic',
                'notified_serial' => 2024010100,
                'notify_pending' => true,
            ],
        ], $rows);
    }

    public function testForwardListWithoutOptionalColumns(): void
    {
        $rows = $this->repository(false)->getZones('all', 0, 'all', 0, 9999, 'name', 'ASC', true, false, false, false, false);

        $this->assertSame([
            'id' => 7,
            'name' => 'signed.example',
            'utf8_name' => 'signed.example',
            'type' => 'MASTER',
            'count_records' => 0,
            'is_disabled' => false,
            'is_missing_soa' => false,
            'soa_health' => 'unknown',
            'comment' => 'signed zone',
            'secured' => true,
            'owners' => ['alice', 'bob'],
            'full_names' => ['Alice A', ''],
            'users' => ['alice', 'bob'],
        ], $rows['signed.example']);
    }

    public function testForwardListIsEmptyForANonViewingPermission(): void
    {
        $this->assertSame([], $this->repository(false)->getZones('none'));
    }
}
