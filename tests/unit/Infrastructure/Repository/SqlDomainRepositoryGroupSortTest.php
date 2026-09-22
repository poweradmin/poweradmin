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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Domain\Model\ZoneSummary;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Sort-by-group support in SqlDomainRepository::getZones (Issue #1051): the
 * listing orders by the zone's lowest group name and the group join must not
 * inflate the record count.
 *
 * Fixture: z-alpha belongs to the "ops" group, z-beta to "dev" and "ops",
 * z-gamma to "zulu". Only z-beta carries records.
 */
#[CoversClass(SqlDomainRepository::class)]
class SqlDomainRepositoryGroupSortTest extends SqliteIntegrationTestCase
{
    private const ALPHA = 1;
    private const BETA = 2;
    private const GAMMA = 3;

    private const OPS_GROUP = 5;
    private const DEV_GROUP = 6;
    private const ZULU_GROUP = 7;

    private SqlDomainRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        $this->db->exec("ALTER TABLE zones ADD COLUMN comment TEXT");
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE cryptokeys (id INTEGER PRIMARY KEY, domain_id INTEGER, active INTEGER)");
        $this->db->exec("CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY, domain_id INTEGER, kind TEXT, content TEXT)");

        $this->db->exec("INSERT INTO user_groups (id, name, perm_templ) VALUES
            (" . self::OPS_GROUP . ", 'ops', 1), (" . self::DEV_GROUP . ", 'dev', 1),
            (" . self::ZULU_GROUP . ", 'zulu', 1)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES
            (" . self::ALPHA . ", 'z-alpha.example.com', 'MASTER'),
            (" . self::BETA . ", 'z-beta.example.com', 'MASTER'),
            (" . self::GAMMA . ", 'z-gamma.example.com', 'NATIVE')");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES
            (" . self::ALPHA . ", " . self::ADMIN_USER_ID . "),
            (" . self::BETA . ", " . self::ADMIN_USER_ID . "),
            (" . self::GAMMA . ", " . self::ADMIN_USER_ID . ")");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES
            (" . self::ALPHA . ", " . self::OPS_GROUP . "),
            (" . self::BETA . ", " . self::DEV_GROUP . "),
            (" . self::BETA . ", " . self::OPS_GROUP . "),
            (" . self::GAMMA . ", " . self::ZULU_GROUP . ")");
        $this->db->exec("INSERT INTO records (domain_id, name, type, content) VALUES
            (" . self::BETA . ", 'z-beta.example.com', 'SOA', 'ns1 hostmaster 1 1 1 1 1'),
            (" . self::BETA . ", 'z-beta.example.com', 'NS', 'ns1.example.com'),
            (" . self::BETA . ", 'www.z-beta.example.com', 'A', '192.0.2.1'),
            (" . self::BETA . ", 'z-beta.example.com', NULL, '')");

        $this->repository = new SqlDomainRepository($this->db, $this->config);
    }

    #[Test]
    public function unpaginatedGroupSortOrdersByTheLowestGroupName(): void
    {
        $ascending = $this->repository->getZones('all', 0, 'all', 0, 1000, 'group', 'ASC');
        $descending = $this->repository->getZones('all', 0, 'all', 0, 1000, 'group', 'DESC');

        // z-beta's lowest group is "dev", z-alpha's is "ops", z-gamma's is "zulu"
        $this->assertSame(['z-beta.example.com', 'z-alpha.example.com', 'z-gamma.example.com'], array_keys($ascending));
        $this->assertSame(['z-gamma.example.com', 'z-alpha.example.com', 'z-beta.example.com'], array_keys($descending));
    }

    #[Test]
    public function groupSortDoesNotInflateTheRecordCount(): void
    {
        $zones = $this->repository->getZones('all', 0, 'all', 0, 1000, 'group', 'ASC');

        // beta joins two groups, so a non-distinct count would report six
        $this->assertSame(3, $zones['z-beta.example.com']->recordCount);
        $this->assertSame(0, $zones['z-alpha.example.com']->recordCount);
    }

    #[Test]
    public function paginatedGroupSortPagesByGroupNameAcrossTheWholeListing(): void
    {
        // letter != 'all' AND rowamount < DEFAULT_MAX_ROWS takes the limited_domains path
        $firstPage = $this->repository->getZones('all', 0, 'z', 0, 2, 'group', 'ASC');
        $secondPage = $this->repository->getZones('all', 0, 'z', 2, 2, 'group', 'ASC');

        $this->assertSame(['z-beta.example.com', 'z-alpha.example.com'], array_keys($firstPage));
        $this->assertSame(['z-gamma.example.com'], array_keys($secondPage));
    }

    #[Test]
    public function ownerSortOrdersByUsername(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (20, 'zed', 1)");
        $this->db->exec("UPDATE zones SET owner = 20 WHERE domain_id = " . self::ALPHA);

        $ascending = $this->repository->getZones('all', 0, 'all', 0, 1000, 'owner', 'ASC');

        $this->assertSame(['admin'], $ascending['z-beta.example.com']->owners);
        $this->assertSame(['zed'], $ascending['z-alpha.example.com']->owners);
        $this->assertSame('z-alpha.example.com', array_key_last($ascending));
    }

    #[Test]
    public function sortingByAHiddenRecordCountColumnFallsBackToName(): void
    {
        // Turning the Records column off drops count_records from the allowed
        // sort keys, but a session set while it was visible still asks for it
        $zones = $this->repository->getZones('all', 0, 'all', 0, 25, 'count_records', 'ASC', false, null, null, true, false);

        $this->assertSame(
            ['z-alpha.example.com', 'z-beta.example.com', 'z-gamma.example.com'],
            array_keys($zones)
        );
        $this->assertContainsOnlyInstancesOf(ZoneSummary::class, $zones);
    }
}
