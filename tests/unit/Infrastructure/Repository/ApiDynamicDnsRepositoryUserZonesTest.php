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
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Repository\ApiDynamicDnsRepository;

/**
 * getUserZones must resolve zones by the canonical id COALESCE(domain_id, id).
 *
 * In API mode a zone this application created has domain_id NULL, so selecting the bare
 * column yielded 0 and getZoneNameById(0) dropped the zone from the DDNS list silently.
 */
class ApiDynamicDnsRepositoryUserZonesTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)");
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'zone_content_edit_own')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (10, 'Editor')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (10, 1)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (1, 'ddns', 10)");
    }

    private function repository(DnsBackendProvider $provider): ApiDynamicDnsRepository
    {
        return new ApiDynamicDnsRepository(
            $this->db,
            $this->createMock(SOARecordManagerInterface::class),
            $provider
        );
    }

    private function providerReturningNames(array $namesById): DnsBackendProvider
    {
        $provider = $this->createMock(DnsBackendProvider::class);
        $provider->method('getZoneNameById')
            ->willReturnCallback(static fn(int $id): ?string => $namesById[$id] ?? null);
        return $provider;
    }

    public function testApiCreatedZoneWithNullDomainIdIsReturned(): void
    {
        // The regression: an API-mode zone row carries domain_id NULL and id as the canonical id.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (55, NULL, 'api.example', 1)");

        $zones = $this->repository($this->providerReturningNames([55 => 'api.example']))
            ->getUserZones(new User(1, 'hash', false));

        $this->assertSame([55 => 'api.example'], $zones);
    }

    public function testZoneStrandedAtDomainIdZeroIsReturned(): void
    {
        // A row left at 0 by an interrupted createZone; bare COALESCE resolved it to 0.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (66, 0, 'zero.example', 1)");

        $zones = $this->repository($this->providerReturningNames([66 => 'zero.example']))
            ->getUserZones(new User(1, 'hash', false));

        $this->assertSame([66 => 'zero.example'], $zones);
    }

    public function testMigratedZoneStillResolvesByDomainId(): void
    {
        // A zone migrated from SQL mode keeps a populated domain_id, which must still win.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (7, 201, 'sql.example', 1)");

        $zones = $this->repository($this->providerReturningNames([201 => 'sql.example']))
            ->getUserZones(new User(1, 'hash', false));

        $this->assertSame([201 => 'sql.example'], $zones);
    }

    public function testPlaceholderOwnershipRowStillResolves(): void
    {
        // Placeholder ownership rows (zone_name NULL) point at the real zone via domain_id.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (90, 300, NULL, 1)");

        $zones = $this->repository($this->providerReturningNames([300 => 'placeholder.example']))
            ->getUserZones(new User(1, 'hash', false));

        $this->assertSame([300 => 'placeholder.example'], $zones);
    }

    public function testGroupOwnedZoneIsReturned(): void
    {
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (100, 'Editors', 10)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (1, 100)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (401, 100)");

        $zones = $this->repository($this->providerReturningNames([401 => 'group.example']))
            ->getUserZones(new User(1, 'hash', false));

        $this->assertSame([401 => 'group.example'], $zones);
    }

    public function testZoneWithoutAnEditGrantIsNotReturned(): void
    {
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (11, 'NoEdit')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (2, 'viewer', 11)");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (60, NULL, 'denied.example', 2)");

        $zones = $this->repository($this->providerReturningNames([60 => 'denied.example']))
            ->getUserZones(new User(2, 'hash', false));

        $this->assertSame([], $zones);
    }
}
