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
use PDOException;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ZoneOverlapService;
use Poweradmin\Infrastructure\Configuration\FakeConfiguration;

/**
 * Integration tests for ZoneOverlapService against a real MariaDB.
 *
 * These exercise the actual ancestor (IN) and descendant (LIKE ... ESCAPE)
 * SQL against the domains table - behavior the mocked unit tests cannot cover.
 * Ownership is mocked so the focus stays on the queries.
 *
 * Requires a running devcontainer. Skipped automatically if unavailable.
 * Run with: composer tests:integration
 */
class ZoneOverlapServiceIntegrationTest extends TestCase
{
    private const DB_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=pdns';
    private const DB_USER = 'pdns';
    private const DB_PASS = 'poweradmin';
    private const PREFIX = 'zos-it-';

    private ?PDO $db = null;
    private const USER_ID = 4242;

    protected function setUp(): void
    {
        try {
            $this->db = new PDO(self::DB_DSN, self::DB_USER, self::DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException) {
            $this->markTestSkipped('Devcontainer MariaDB not available.');
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->db->prepare("DELETE FROM domains WHERE name LIKE :p")
            ->execute([':p' => '%' . self::PREFIX . '%']);
    }

    private function seedDomain(string $name): void
    {
        $stmt = $this->db->prepare("INSERT INTO domains (name, type) VALUES (:name, 'NATIVE')");
        $stmt->execute([':name' => $name]);
    }

    /**
     * @param list<int> $ownedIds domain ids the user owns (others count as another owner)
     */
    private function makeService(array $ownedIds = []): ZoneOverlapService
    {
        $config = new FakeConfiguration([
            'dns' => ['parent_zone_ownership_check' => true],
            'database' => ['pdns_db_name' => null],
        ]);

        $permission = $this->createMock(ApiPermissionService::class);
        $permission->method('userHasPermission')->willReturn(false);
        $permission->method('userOwnsZone')->willReturnCallback(
            fn(int $userId, int $zoneId): bool => in_array($zoneId, $ownedIds, true)
        );

        return new ZoneOverlapService($this->db, $config, $permission);
    }

    public function testDetectsAncestorZone(): void
    {
        $parent = self::PREFIX . 'parent.example.test';
        $this->seedDomain($parent);

        $conflict = $this->makeService()->findConflictingZone('child.' . $parent, self::USER_ID);

        $this->assertSame($parent, $conflict);
    }

    public function testDetectsDescendantZone(): void
    {
        $parent = self::PREFIX . 'umbrella.example.test';
        $child = 'leaf.' . $parent;
        $this->seedDomain($child);

        $conflict = $this->makeService()->findConflictingZone($parent, self::USER_ID);

        $this->assertSame($child, $conflict);
    }

    public function testAllowsWhenNoOverlapExists(): void
    {
        $this->seedDomain(self::PREFIX . 'lonely.example.test');

        $conflict = $this->makeService()->findConflictingZone(self::PREFIX . 'other.example.test', self::USER_ID);

        $this->assertNull($conflict);
    }

    public function testMatchIsCaseInsensitiveAcrossBackends(): void
    {
        // A mixed-case stored parent must still be detected for a lowercase child,
        // even on case-sensitive collations (PostgreSQL/SQLite).
        $parent = self::PREFIX . 'CaseParent.example.test';
        $this->seedDomain($parent);

        $conflict = $this->makeService()->findConflictingZone(
            'child.' . self::PREFIX . 'caseparent.example.test',
            self::USER_ID
        );

        $this->assertSame(self::PREFIX . 'caseparent.example.test', $conflict);
    }

    public function testUnderscoreInZoneNameIsMatchedLiterally(): void
    {
        // A new zone name containing an underscore must not let the LIKE wildcard
        // match an unrelated descendant where another character sits in its place.
        $newZone = self::PREFIX . 'ovl_us.example.test';
        $this->seedDomain('sub.' . self::PREFIX . 'ovlZus.example.test');

        $conflict = $this->makeService()->findConflictingZone($newZone, self::USER_ID);

        $this->assertNull($conflict, 'The "_" must be escaped so it does not match "Z".');
    }
}
