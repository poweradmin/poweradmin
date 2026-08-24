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
 * Extra owners live in `zones` rows with a NULL zone_name, keyed by the canonical
 * zone id - COALESCE(domain_id, id) - not by the canonical row's primary key.
 * Comparing them against the primary key hid every co-owner: ownership checks
 * denied access, the owners list came back short, and deleting a zone left the
 * extra rows behind.
 */
#[CoversClass(ApiZoneRepository::class)]
class ApiZoneRepositoryPlaceholderOwnershipTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT,
            zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES
            (1, 'primary', 'Primary Owner'), (2, 'extra', 'Extra Owner'), (3, 'stranger', 'Stranger')");

        // Canonical row: primary key 4, canonical id 2905. The extra ownership row
        // is keyed by 2905, the value a caller is handed in API mode.
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id) VALUES
            (4, 2905, 'shared.example.com', 'MASTER', '', '', 1, 0),
            (5, 2905, NULL, NULL, NULL, NULL, 2, 0)");
    }

    private function repository(): ApiZoneRepository
    {
        return new ApiZoneRepository(
            $this->db,
            $this->createMock(DnsBackendProvider::class),
            'sqlite',
            $this->createMock(ConfigurationManager::class)
        );
    }

    #[Test]
    public function theExtraOwnerIsListedAlongsideThePrimaryOwner(): void
    {
        $owners = array_column($this->repository()->getZoneOwners(4), 'username');
        sort($owners);

        $this->assertSame(['extra', 'primary'], $owners);
    }

    #[Test]
    public function theExtraOwnerPassesTheOwnershipCheck(): void
    {
        $this->assertTrue($this->repository()->isUserZoneOwner(4, 2));
    }

    #[Test]
    public function theExtraOwnerCanSeeTheZone(): void
    {
        // zoneExists doubles as the access gate on the internal zone endpoint, so a
        // miss here is a 404 on the user's own zone.
        $this->assertTrue($this->repository()->zoneExists(4, 2));
    }

    #[Test]
    public function aStrangerIsStillRefused(): void
    {
        $this->assertFalse($this->repository()->isUserZoneOwner(4, 3));
        $this->assertFalse($this->repository()->zoneExists(4, 3));
    }

    #[Test]
    public function thePrimaryOwnerIsUnaffected(): void
    {
        $this->assertTrue($this->repository()->isUserZoneOwner(4, 1));
        $this->assertTrue($this->repository()->zoneExists(4, 1));
    }

    #[Test]
    public function anAddedOwnerIsReadBack(): void
    {
        // The write and the read must agree on the key. Keying the insert on the
        // canonical row's primary key instead left the new co-owner invisible.
        $this->assertTrue($this->repository()->addOwnerToZone(4, 3));

        $owners = array_column($this->repository()->getZoneOwners(4), 'username');
        sort($owners);

        $this->assertSame(['extra', 'primary', 'stranger'], $owners);
        $this->assertTrue($this->repository()->isUserZoneOwner(4, 3));
        $this->assertTrue($this->repository()->zoneExists(4, 3));
    }

    #[Test]
    public function anAddedOwnerRowIsKeyedByTheCanonicalId(): void
    {
        $this->repository()->addOwnerToZone(4, 3);

        $written = $this->db->query("SELECT domain_id FROM zones WHERE owner = 3 AND zone_name IS NULL")
            ->fetchColumn();

        $this->assertSame(2905, (int)$written);
    }

    #[Test]
    public function anExtraOwnerCanBeRemoved(): void
    {
        $this->assertTrue($this->repository()->removeOwnerFromZone(4, 2));

        $this->assertSame(['primary'], array_column($this->repository()->getZoneOwners(4), 'username'));
        $this->assertFalse($this->repository()->isUserZoneOwner(4, 2));
    }

    #[Test]
    public function deletingAZoneAlsoRemovesItsGroupOwnership(): void
    {
        // zones_groups is keyed by the canonical zone id, so cleaning up by the
        // canonical row's own primary key would leave the row orphaned.
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (2905, 9)");

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('deleteZone')->willReturn(true);
        $repository = new ApiZoneRepository(
            $this->db,
            $backend,
            'sqlite',
            $this->createMock(ConfigurationManager::class)
        );

        $this->assertTrue($repository->deleteZone(4));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM zones_groups")->fetchColumn());
    }

    #[Test]
    public function aZoneWithoutExtraOwnersListsOnlyItsOwn(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id)
            VALUES (7, 3001, 'solo.example.com', 'MASTER', '', '', 1, 0)");

        $this->assertSame(['primary'], array_column($this->repository()->getZoneOwners(7), 'username'));
    }
}
