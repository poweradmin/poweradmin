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

namespace Poweradmin\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\HybridPermissionService;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;

/**
 * Zone ownership resolves through the canonical id expression COALESCE(NULLIF(domain_id, 0), id).
 * In SQL backend mode domain_id is a foreign key into domains and is always populated, so the
 * fallback to the row's own id must never fire. If it did, the two id spaces overlap and a user
 * would be handed a zone belonging to somebody else.
 *
 * This pins every ownership entry point against a SQL-mode fixture, including the collision the
 * CanonicalZoneSql docblock warns about: a zones row whose primary key equals a different zone's
 * domain_id.
 *
 * Deliberately in-memory SQLite rather than the devcontainer engines: these repositories address
 * the real `zones` and `users` tables by name, so pointing them at a live database would drop and
 * rewrite production data. Cross-engine behaviour of the expression itself is covered separately
 * by CanonicalZoneIdIntegrationTest, which works on its own throwaway tables.
 */
class SqlModeZoneOwnershipRegressionTest extends TestCase
{
    private const ALICE = 10;
    private const BOB = 20;
    private const CAROL = 30;
    private const DAVE = 40;

    private const EDITOR_TEMPL = 20;

    private PDO $db;
    private ApiPermissionService $apiPermissions;
    private HybridPermissionService $hybridPermissions;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, owner INTEGER, comment TEXT, zone_templ_id INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)");
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)");

        $this->seedSqlModeFixture();

        $this->apiPermissions = new ApiPermissionService($this->db);
        $this->hybridPermissions = new HybridPermissionService(
            $this->db,
            $this->createMock(UserGroupRepositoryInterface::class),
            $this->createMock(UserGroupMemberRepositoryInterface::class)
        );
    }

    /**
     * A SQL-mode install: every zones row carries a real, non-zero domains.id and no zone_name.
     * Zone gamma is the collision - its zones.id (100) equals zone alpha's domain_id (100).
     */
    private function seedSqlModeFixture(): void
    {
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES
            (100, 'alpha.example.com', 'MASTER'),
            (101, 'beta.example.com', 'MASTER'),
            (102, 'gamma.example.com', 'MASTER')");

        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES
            (1, 100, NULL, " . self::ALICE . "),
            (2, 101, NULL, " . self::BOB . "),
            (100, 102, NULL, " . self::CAROL . ")");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (41, 'zone_content_edit_own'), (42, 'zone_content_view_own')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::EDITOR_TEMPL . ", 'Editor')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES
            (" . self::EDITOR_TEMPL . ", 41),
            (" . self::EDITOR_TEMPL . ", 42)");
        $this->db->exec("INSERT INTO users (id, username, fullname, perm_templ) VALUES
            (" . self::ALICE . ", 'alice', 'Alice', " . self::EDITOR_TEMPL . "),
            (" . self::BOB . ", 'bob', 'Bob', " . self::EDITOR_TEMPL . "),
            (" . self::CAROL . ", 'carol', 'Carol', " . self::EDITOR_TEMPL . "),
            (" . self::DAVE . ", 'dave', 'Dave', " . self::EDITOR_TEMPL . ")");

        // Dave reaches beta only through a group, which keys on the canonical id already.
        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES (5, 'Editors', " . self::EDITOR_TEMPL . ")");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::DAVE . ", 5)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (101, 5)");
    }

    /**
     * The full (user x zone) truth table. Zone ids 100/101/102 are the domains ids callers
     * actually hold; 1 and 2 are zones primary keys that must never grant anything.
     *
     * @return array<string, array{0: int, 1: int, 2: bool}>
     */
    public static function ownershipMatrix(): array
    {
        return [
            'alice owns alpha' => [self::ALICE, 100, true],
            'alice not beta' => [self::ALICE, 101, false],
            'alice not gamma' => [self::ALICE, 102, false],
            'bob owns beta' => [self::BOB, 101, true],
            'bob not alpha' => [self::BOB, 100, false],
            'bob not gamma' => [self::BOB, 102, false],
            'carol owns gamma' => [self::CAROL, 102, true],
            'carol not alpha via her row id' => [self::CAROL, 100, false],
            'carol not beta' => [self::CAROL, 101, false],
            'dave owns beta by group' => [self::DAVE, 101, true],
            'dave not alpha' => [self::DAVE, 100, false],
            'dave not gamma' => [self::DAVE, 102, false],
            'alice not reachable by her row id' => [self::ALICE, 1, false],
            'bob not reachable by his row id' => [self::BOB, 2, false],
        ];
    }

    #[DataProvider('ownershipMatrix')]
    public function testWebUiOwnershipMatrixIsUnchanged(int $userId, int $zoneId, bool $owns): void
    {
        // UserManager reads the acting user from the session.
        $_SESSION['userid'] = $userId;
        $this->assertSame($owns, UserManager::verifyUserIsOwnerZoneId($this->db, $zoneId));
        unset($_SESSION['userid']);
    }

    #[DataProvider('ownershipMatrix')]
    public function testApiOwnershipMatrixIsUnchanged(int $userId, int $zoneId, bool $owns): void
    {
        $this->assertSame($owns, $this->apiPermissions->userOwnsZone($userId, $zoneId));
    }

    #[DataProvider('ownershipMatrix')]
    public function testHybridPermissionsAgreeWithOwnership(int $userId, int $zoneId, bool $owns): void
    {
        $permissions = $this->hybridPermissions->getUserPermissionsForZone($userId, $zoneId)['permissions'];

        $this->assertSame($owns, in_array('zone_content_edit_own', $permissions, true));
    }

    /**
     * The collision the canonical-id rule exists to survive: zones.id 100 belongs to carol's
     * gamma, while 100 is also alpha's domain_id. Resolving zone 100 must reach alpha, and
     * carol must gain nothing from her row's primary key.
     */
    public function testRowIdCollisionGrantsNothingAcrossZones(): void
    {
        $_SESSION['userid'] = self::ALICE;
        $this->assertTrue(UserManager::verifyUserIsOwnerZoneId($this->db, 100), 'alpha lost its owner');
        $_SESSION['userid'] = self::CAROL;
        $this->assertFalse(UserManager::verifyUserIsOwnerZoneId($this->db, 100), 'carol cross-granted alpha');
        unset($_SESSION['userid']);

        $this->assertTrue($this->apiPermissions->userOwnsZone(self::ALICE, 100));
        $this->assertFalse($this->apiPermissions->userOwnsZone(self::CAROL, 100));
    }

    public function testVisibleZoneIdsAreTheDomainsIdsNotTheRowIds(): void
    {
        $this->assertSame([100], $this->apiPermissions->getUserVisibleZoneIds(self::ALICE));
        $this->assertSame([102], $this->apiPermissions->getUserVisibleZoneIds(self::CAROL));

        $daveVisible = $this->apiPermissions->getUserVisibleZoneIds(self::DAVE);
        sort($daveVisible);
        $this->assertSame([101], $daveVisible, 'group-owned zones must still resolve');
    }

    public function testAccessibleZonesReportTheDomainsIds(): void
    {
        $alice = $this->hybridPermissions->getUserAccessibleZones(self::ALICE);
        $this->assertSame([100], $alice['user_zones']);
        $this->assertSame([], $alice['group_zones']);

        $dave = $this->hybridPermissions->getUserAccessibleZones(self::DAVE);
        $this->assertSame([], $dave['user_zones']);
        $this->assertSame([101], $dave['group_zones']);
    }

    public function testNoZonesRowGainedAFabricatedDomainId(): void
    {
        // Nothing in the read path may rewrite a SQL-mode row; the fixture must come back
        // exactly as seeded.
        $rows = $this->db->query("SELECT id, domain_id FROM zones ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($rows as $row) {
            $actual[(int)$row['id']] = (int)$row['domain_id'];
        }

        $this->assertSame([1 => 100, 2 => 101, 100 => 102], $actual);
    }
}
