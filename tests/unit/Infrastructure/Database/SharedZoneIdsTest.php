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

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\SharedZoneIds;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;

/**
 * In API backend mode a zone this application created (domain_id = its own id) and a
 * migrated zone (domain_id from the old SQL install) can share a canonical id. The id
 * resolves to the self-consistent row, and ownership must not leak across to it from the
 * other zone's owner, extra-owner rows or group grants.
 */
class SharedZoneIdsTest extends TestCase
{
    private const CREATED_OWNER = 1;
    private const MIGRATED_OWNER = 2;
    private const EXTRA_OWNER = 3;
    private const GROUP_MEMBER = 4;

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, group_id INTEGER, user_id INTEGER)");
    }

    private function seed(int $id, ?int $domainId, ?string $zoneName, ?int $owner): void
    {
        $stmt = $this->db->prepare("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (:id, :did, :name, :owner)");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':did', $domainId, $domainId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':name', $zoneName, $zoneName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':owner', $owner, $owner === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Zone 5 created here, migrated zone 12 carrying domain_id 5, an extra owner and a group
     * grant keyed by 5, and an unrelated zone 30 owned through the same extra-owner shape.
     */
    private function seedCollision(): void
    {
        $this->seed(5, 5, 'created.example.com', self::CREATED_OWNER);
        $this->seed(12, 5, 'migrated.example.com', self::MIGRATED_OWNER);
        $this->seed(13, 5, null, self::EXTRA_OWNER);
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (5, 7)");
        $this->db->exec("INSERT INTO user_group_members (group_id, user_id) VALUES (7, " . self::GROUP_MEMBER . ")");

        $this->seed(30, 30, 'plain.example.com', self::MIGRATED_OWNER);
        $this->seed(31, 30, null, self::EXTRA_OWNER);
    }

    private function users(bool $apiBackend = true): DbUserRepository
    {
        return new DbUserRepository($this->db, $this->createStub(ConfigurationInterface::class), $apiBackend);
    }

    public function testAnIdTwoNamedZonesShareIsShared(): void
    {
        $this->seedCollision();

        $this->assertTrue(SharedZoneIds::isShared($this->db, 5));
        $this->assertFalse(SharedZoneIds::isShared($this->db, 30), 'An extra-owner row is not a second zone');
        $this->assertFalse(SharedZoneIds::isShared($this->db, 12), 'A row id that is not a canonical id is not shared');
    }

    public function testDifferentIdSpacesAloneAreNotShared(): void
    {
        // Migrated rows: 7 is X's canonical id and only Y's row id
        $this->seed(3, 7, 'x.example.com', self::MIGRATED_OWNER);
        $this->seed(7, 12, 'y.example.com', self::MIGRATED_OWNER);

        $this->assertFalse(SharedZoneIds::isShared($this->db, 7));
        $this->assertFalse(SharedZoneIds::isShared($this->db, 12));
    }

    public function testOnlyTheDirectOwnerOfTheResolvedZoneOwnsASharedId(): void
    {
        $this->seedCollision();
        $users = $this->users();

        $this->assertTrue($users->userOwnsZone(self::CREATED_OWNER, 5));
        $this->assertFalse($users->userOwnsZone(self::MIGRATED_OWNER, 5), 'The migrated zone owner must not act on the created zone');
        $this->assertFalse($users->userOwnsZone(self::EXTRA_OWNER, 5), 'An extra-owner row keyed by a shared id cannot be attributed');
        $this->assertFalse($users->userOwnsZone(self::GROUP_MEMBER, 5), 'A group grant keyed by a shared id cannot be attributed');
    }

    public function testOwnershipOfAnUnsharedIdIsUnchanged(): void
    {
        $this->seedCollision();
        $users = $this->users();

        $this->assertTrue($users->userOwnsZone(self::MIGRATED_OWNER, 30));
        $this->assertTrue($users->userOwnsZone(self::EXTRA_OWNER, 30));
    }

    public function testOwnedZoneListsDropSharedIdsTheUserDoesNotDirectlyOwn(): void
    {
        $this->seedCollision();
        $users = $this->users();

        $this->assertSame([5], $users->getUserOwnedZoneIds(self::CREATED_OWNER));
        $this->assertSame([30], $users->getUserOwnedZoneIds(self::MIGRATED_OWNER));
        $this->assertSame([30], $users->getUserOwnedZoneIds(self::EXTRA_OWNER));
        $this->assertSame([], $users->getUserOwnedZoneIds(self::GROUP_MEMBER));
    }

    public function testSqlModeKeepsMatchingDomainIds(): void
    {
        $this->seedCollision();

        $this->assertTrue($this->users(false)->userOwnsZone(self::MIGRATED_OWNER, 5));
    }

    public function testTheGroupIndexDropsGrantsOnASharedId(): void
    {
        $this->seedCollision();
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (30, 8)");

        $this->assertSame([30 => [8]], (new DbZoneGroupRepository($this->db, null, true))->findGroupIdsByDomainIds([5, 30]));
        $this->assertSame([5 => [7], 30 => [8]], (new DbZoneGroupRepository($this->db, null, false))->findGroupIdsByDomainIds([5, 30]));
    }

    public function testListsBuiltFromTheBackendKeepOnlyTheZoneASharedIdOpens(): void
    {
        $this->seedCollision();

        $opened = SharedZoneIds::openedNames($this->db);

        $this->assertSame([5 => 'created.example.com'], $opened);
        $this->assertTrue(SharedZoneIds::isOpenedZone($opened, ['id' => 5, 'name' => 'created.example.com.']));
        $this->assertFalse(SharedZoneIds::isOpenedZone($opened, ['id' => 5, 'name' => 'migrated.example.com.']));
        $this->assertTrue(SharedZoneIds::isOpenedZone($opened, ['id' => 30, 'name' => 'plain.example.com.']));
    }
}
