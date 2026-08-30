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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\ZoneAccountSyncService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * A missed ownership lookup here does not merely skip the sync: it pushes an empty account
 * and wipes whatever PowerDNS held for the zone.
 */
class ZoneAccountSyncCanonicalZoneTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, owner INTEGER, zone_name TEXT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)");
        $this->db->exec("INSERT INTO users (id, username) VALUES (1, 'alice')");
    }

    /**
     * @param list<array{0: int, 1: string}> $pushed collects the domainId and account pairs
     */
    private function serviceExpecting(array &$pushed): ZoneAccountSyncService
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null) => ($group === 'dns' && $key === 'sync_zone_owner_to_account') ? true : $default
        );

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('updateZoneAccount')->willReturnCallback(
            function (int $domainId, string $account) use (&$pushed): bool {
                $pushed[] = [$domainId, $account];
                return true;
            }
        );

        return new ZoneAccountSyncService($this->db, $config, $backend);
    }

    public function testStrandedZoneKeepsItsAccountInsteadOfBeingWiped(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (55, 0, 1, 'example.com')");

        $pushed = [];
        $this->serviceExpecting($pushed)->syncZoneAccount(55);

        $this->assertSame([[55, 'alice']], $pushed);
    }

    public function testNullDomainIdZoneAlsoResolves(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (56, NULL, 1, 'example.com')");

        $pushed = [];
        $this->serviceExpecting($pushed)->syncZoneAccount(56);

        $this->assertSame([[56, 'alice']], $pushed);
    }

    public function testMigratedZoneStillResolvesByDomainId(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (7, 201, 1, 'example.com')");

        $pushed = [];
        $this->serviceExpecting($pushed)->syncZoneAccount(201);

        $this->assertSame([[201, 'alice']], $pushed);
    }

    public function testAZoneWithNoOwnerStillClearsTheAccount(): void
    {
        // The wipe is intended when the zone genuinely has no direct owner.
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (60, 60, NULL, 'orphan.example.com')");

        $pushed = [];
        $this->serviceExpecting($pushed)->syncZoneAccount(60);

        $this->assertSame([[60, '']], $pushed);
    }
}
